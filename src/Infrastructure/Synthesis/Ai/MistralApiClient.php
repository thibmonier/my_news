<?php

declare(strict_types=1);

namespace App\Infrastructure\Synthesis\Ai;

use App\Domain\Synthesis\MistralClientInterface;
use App\Domain\Synthesis\SynthesisUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adapter Infrastructure — Client HTTP vers l'API Mistral.
 *
 * Implémente MistralClientInterface (port Domain) via Symfony HttpClient.
 *
 * Spécifications techniques :
 * - Endpoint  : POST https://api.mistral.ai/v1/chat/completions
 * - Timeout   : 15s (T-010-04) — SynthesisUnavailableException si dépassé
 * - Modèle    : mistral-small-latest (équilibre vitesse/qualité, tarif EU)
 * - Température: 0.3 (réponses cohérentes, faible variabilité)
 * - Max tokens : 600 (couverture 200 mots condensé + 3 points clés + sources)
 *
 * Sécurité RGPD :
 * - Jamais de PII (email, UUID user, IP) dans les prompts envoyés
 * - Logging : url_hash uniquement (jamais le prompt complet en prod)
 * - L'assertion PII-free est vérifiée par les tests unitaires (T-010-11)
 *
 * OWASP A05 (Mishandling Exceptional Conditions) :
 * - Toute exception réseau/HTTP est wrappée en SynthesisUnavailableException
 * - Aucune stack trace exposée dans la réponse API (handler Presentation)
 *
 * Deptrac : Infrastructure → Domain (MistralClientInterface, SynthesisUnavailableException).
 */
final class MistralApiClient implements MistralClientInterface
{
    private const API_URL = 'https://api.mistral.ai/v1/chat/completions';
    private const MODEL = 'mistral-small-latest';
    private const TIMEOUT = 15.0;   // secondes — T-010-04
    private const MAX_TOKENS = 600;
    private const TEMPERATURE = 0.3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Soumet un prompt à Mistral et retourne la réponse textuelle.
     *
     * @param string $systemPrompt Prompt système contrôlant le format de sortie
     * @param string $userContent Contenu de l'article à synthétiser (PII-free)
     *
     * @throws SynthesisUnavailableException si timeout, erreur réseau, ou HTTP 5xx
     */
    public function complete(string $systemPrompt, string $userContent): string
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
                        'model' => self::MODEL,
                        'temperature' => self::TEMPERATURE,
                        'max_tokens' => self::MAX_TOKENS,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => $systemPrompt,
                            ],
                            [
                                'role' => 'user',
                                'content' => $userContent,
                            ],
                        ],
                    ],
                ],
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->warning('synthesis.mistral_http_error', [
                    'status_code' => $statusCode,
                    // PII-safe : jamais le prompt dans les logs
                ]);

                throw new SynthesisUnavailableException(\sprintf('Mistral API returned HTTP %d', $statusCode));
            }

            /** @var array{choices: array<int, array{message: array{content: string}}>} $data */
            $data = $response->toArray();

            $content = $data['choices'][0]['message']['content'] ?? '';

            if ('' === $content) {
                throw new SynthesisUnavailableException('Mistral returned an empty response');
            }

            return $content;
        } catch (SynthesisUnavailableException $e) {
            throw $e;
        } catch (TransportExceptionInterface $e) {
            // Timeout ou erreur réseau
            $this->logger->warning('synthesis.mistral_transport_error', [
                'error' => $e->getMessage(),
                // PII-safe : jamais le prompt ni l'URL dans les logs
            ]);

            throw new SynthesisUnavailableException('Mistral transport error: ' . $e->getMessage(), $e);
        } catch (\Throwable $e) {
            $this->logger->warning('synthesis.mistral_unexpected_error', [
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw new SynthesisUnavailableException('Mistral unexpected error: ' . $e->getMessage(), $e);
        }
    }
}
