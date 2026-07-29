<?php

declare(strict_types=1);

namespace App\Infrastructure\Summary\Ai;

use App\Domain\Summary\ArticleSummary;
use App\Domain\Summary\SummaryClientInterface;
use App\Domain\Summary\SummaryUnavailableException;
use App\Domain\Synthesis\MistralClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Adapter Infrastructure — Client Mistral pour la génération de condensés IA (US-004).
 *
 * Implémente SummaryClientInterface (port Domain) en réutilisant MistralClientInterface
 * (port Synthesis existant — DRY, pas de duplication HTTP).
 *
 * Prompt système :
 * - Impose 3-4 puces ≤ 120 chars chacune
 * - Réponse en JSON array uniquement
 * - Même langue que l'article (pas de traduction)
 * - JAMAIS de PII dans le prompt (RGPD T-004-11)
 *
 * Modèle : mistral-small-latest (version loguée pour traçabilité RGPD).
 *
 * Deptrac : Infrastructure → Domain (SummaryClientInterface, MistralClientInterface, ArticleSummary).
 */
final class MistralSummaryClient implements SummaryClientInterface
{
    private const MODEL_VERSION = 'mistral-small-latest';

    /**
     * Prompt système imposant le format JSON array de 3-4 puces ≤ 120 chars.
     *
     * PII-safe : aucun UUID utilisateur, email ou IP dans ce prompt.
     * La langue de l'article est conservée (pas de traduction).
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
        private readonly MistralClientInterface $mistralClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * Sécurité RGPD :
     * - $articleText ne contient JAMAIS de PII (garantie par l'appelant ArticleSummaryService)
     * - $articleId est loggué pour traçabilité mais JAMAIS envoyé dans le prompt
     *
     * @throws SummaryUnavailableException si Mistral timeout ou réponse invalide
     */
    public function summarize(string $articleText, string $articleId): ArticleSummary
    {
        try {
            $raw = $this->mistralClient->complete(self::SYSTEM_PROMPT, $articleText);
            $keyPoints = $this->parseJsonResponse($raw);

            $this->logger->info('summary.mistral_generated', [
                'event' => 'summary.mistral_generated',
                'article_id' => $articleId,
                'model' => self::MODEL_VERSION,
                'bullets_count' => \count($keyPoints),
                // PII-safe : jamais le texte de l'article ni le prompt dans les logs
            ]);

            return new ArticleSummary(
                articleId: $articleId,
                keyPoints: $keyPoints,
                modelVersion: self::MODEL_VERSION,
                createdAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
        } catch (SummaryUnavailableException $e) {
            throw $e;
        } catch (\App\Domain\Synthesis\SynthesisUnavailableException $e) {
            throw new SummaryUnavailableException('Mistral indisponible : ' . $e->getMessage(), $e);
        } catch (\Throwable $e) {
            throw new SummaryUnavailableException('Erreur inattendue Mistral : ' . $e->getMessage(), $e);
        }
    }

    /**
     * Parse la réponse JSON de Mistral en tableau de puces validées.
     *
     * Stratégie permissive : si le JSON est invalide, tente d'extraire les lignes.
     * Chaque puce est tronquée à 120 chars si nécessaire (robustesse).
     *
     * @throws SummaryUnavailableException si la réponse ne contient aucune puce exploitable
     *
     * @return list<string> 3 ou 4 puces validées
     */
    private function parseJsonResponse(string $raw): array
    {
        // Extraire le premier JSON array de la réponse
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
                // Fallback sur l'extraction par lignes
            }
        }

        // Fallback : extraire les lignes non vides
        $lines = array_filter(
            array_map('trim', explode("\n", $raw)),
            static fn (string $l): bool => '' !== $l && !str_starts_with($l, '[') && !str_starts_with($l, ']'),
        );
        $keyPoints = $this->extractValidKeyPoints(array_values($lines));

        if (\count($keyPoints) < ArticleSummary::MIN_KEY_POINTS) {
            throw new SummaryUnavailableException('Réponse Mistral insuffisante : moins de ' . ArticleSummary::MIN_KEY_POINTS . ' puces extraites.');
        }

        return \array_slice($keyPoints, 0, ArticleSummary::MAX_KEY_POINTS);
    }

    /**
     * Filtre et tronque les éléments pour les rendre conformes aux invariants.
     *
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

            // Tronquer si nécessaire pour respecter l'invariant
            if (mb_strlen($cleaned) > ArticleSummary::MAX_KEY_POINT_LENGTH) {
                $cleaned = mb_substr($cleaned, 0, ArticleSummary::MAX_KEY_POINT_LENGTH - 1) . '…';
            }

            $result[] = $cleaned;
        }

        return $result;
    }
}
