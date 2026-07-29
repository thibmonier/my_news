<?php

declare(strict_types=1);

namespace App\Infrastructure\Summary\Ai;

use App\Domain\Summary\ArticleSummary;
use App\Domain\Summary\SummaryClientInterface;
use App\Domain\Summary\SummaryUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adapter Infrastructure — Client OpenAI (GPT-4o-mini) pour les condensés IA (US-004).
 *
 * Fallback de MistralSummaryClient quand le circuit breaker Mistral est ouvert.
 * Implémente SummaryClientInterface (même contrat, DIP SOLID).
 *
 * Endpoint : POST https://api.openai.com/v1/chat/completions
 * Modèle   : gpt-4o-mini (équilibre vitesse/coût pour le fallback)
 * Timeout  : 15s (même que Mistral)
 *
 * Sécurité RGPD :
 * - Jamais de PII dans les prompts (articleText uniquement)
 * - $articleId loggué pour traçabilité mais JAMAIS dans le prompt
 *
 * Deptrac : Infrastructure → Domain (SummaryClientInterface, ArticleSummary).
 */
final class OpenAiSummaryClient implements SummaryClientInterface
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const MODEL_VERSION = 'gpt-4o-mini';
    private const TIMEOUT = 15.0;
    private const MAX_TOKENS = 400;
    private const TEMPERATURE = 0.3;

    /**
     * Prompt système identique à MistralSummaryClient (cohérence de format).
     *
     * PII-safe : aucun identifiant utilisateur dans ce prompt.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a news bullet-point extractor for Briefly AI.
        Extract the 3 or 4 most important points from the article.

        RULES:
        - Respond in the SAME LANGUAGE as the article text. Do NOT translate.
        - Return ONLY a valid JSON array of strings, no other text.
        - The array MUST contain exactly 3 or 4 elements.
        - Each element MUST be ≤ 120 characters (including spaces).
        - Each element must be a complete, informative sentence.
        - Do NOT include any user names, emails, personal identifiers or IP addresses.
        - Do NOT include any HTML, Markdown or special formatting.

        Example of valid response:
        ["First key point about the topic.", "Second key point with important detail.", "Third key point concluding the story."]
        PROMPT;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @throws SummaryUnavailableException si OpenAI timeout, erreur réseau, ou réponse invalide
     */
    public function summarize(string $articleText, string $articleId): ArticleSummary
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                self::API_URL,
                [
                    'timeout' => self::TIMEOUT,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'json' => [
                        'model' => self::MODEL_VERSION,
                        'temperature' => self::TEMPERATURE,
                        'max_tokens' => self::MAX_TOKENS,
                        'messages' => [
                            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                            ['role' => 'user', 'content' => $articleText],
                        ],
                    ],
                ],
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->warning('summary.openai_http_error', [
                    'status_code' => $statusCode,
                    // PII-safe
                ]);

                throw new SummaryUnavailableException(\sprintf('OpenAI API a retourné HTTP %d', $statusCode));
            }

            /** @var array{choices: array<int, array{message: array{content: string}}>} $data */
            $data = $response->toArray();
            $content = $data['choices'][0]['message']['content'] ?? '';

            if ('' === $content) {
                throw new SummaryUnavailableException('OpenAI a retourné une réponse vide');
            }

            $keyPoints = $this->parseJsonResponse($content);

            $this->logger->info('summary.openai_generated', [
                'event' => 'summary.openai_generated',
                'article_id' => $articleId,
                'model' => self::MODEL_VERSION,
                'bullets_count' => \count($keyPoints),
            ]);

            return new ArticleSummary(
                articleId: $articleId,
                keyPoints: $keyPoints,
                modelVersion: self::MODEL_VERSION,
                createdAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
        } catch (SummaryUnavailableException $e) {
            throw $e;
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('summary.openai_transport_error', [
                'error' => $e->getMessage(),
            ]);

            throw new SummaryUnavailableException('OpenAI transport error : ' . $e->getMessage(), $e);
        } catch (\Throwable $e) {
            $this->logger->warning('summary.openai_unexpected_error', [
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw new SummaryUnavailableException('OpenAI erreur inattendue : ' . $e->getMessage(), $e);
        }
    }

    /**
     * Parse la réponse JSON d'OpenAI en tableau de puces validées.
     *
     * @throws SummaryUnavailableException si aucune puce exploitable
     *
     * @return list<string>
     */
    private function parseJsonResponse(string $raw): array
    {
        $jsonStart = strpos($raw, '[');
        $jsonEnd = strrpos($raw, ']');

        if (false !== $jsonStart && false !== $jsonEnd && $jsonEnd > $jsonStart) {
            $jsonStr = substr($raw, $jsonStart, $jsonEnd - $jsonStart + 1);

            try {
                /** @var mixed $decoded */
                $decoded = json_decode($jsonStr, true, 512, \JSON_THROW_ON_ERROR);

                if (\is_array($decoded)) {
                    $keyPoints = $this->extractValidKeyPoints($decoded);

                    if (\count($keyPoints) >= ArticleSummary::MIN_KEY_POINTS) {
                        return \array_slice($keyPoints, 0, ArticleSummary::MAX_KEY_POINTS);
                    }
                }
            } catch (\JsonException) {
                // Fallback
            }
        }

        throw new SummaryUnavailableException('Réponse OpenAI invalide : aucun JSON array exploitable.');
    }

    /**
     * @param array<mixed> $items
     *
     * @return list<string>
     */
    private function extractValidKeyPoints(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (!\is_string($item)) {
                continue;
            }

            $cleaned = trim($item, " \t\n\r\0\x0B\"'");

            if ('' === $cleaned) {
                continue;
            }

            if (mb_strlen($cleaned) > ArticleSummary::MAX_KEY_POINT_LENGTH) {
                $cleaned = mb_substr($cleaned, 0, ArticleSummary::MAX_KEY_POINT_LENGTH - 1) . '…';
            }

            $result[] = $cleaned;
        }

        return $result;
    }
}
