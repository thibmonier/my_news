<?php

declare(strict_types=1);

namespace App\Infrastructure\Synthesis\Http;

use App\Domain\Synthesis\ArticleContentFetcherInterface;
use App\Domain\Synthesis\FetchedContent;
use App\Domain\Synthesis\SynthesisUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adapter Infrastructure — Récupération du contenu d'un article via HTTP.
 *
 * Implémente ArticleContentFetcherInterface (port Domain) via Symfony HttpClient.
 *
 * Comportement :
 * - GET sur l'URL fournie (déjà validée SSRF par SynthesisService)
 * - Timeout  : 10s
 * - User-Agent : BrieflyAI/1.0 (politique robots.txt)
 * - Strip des balises HTML pour extraire le texte brut
 * - Détection paywall heuristique :
 *     - HTTP 402 ou 403 → isPartial = true
 *     - Contenu < 500 caractères → isPartial = true
 *
 * OWASP A05 : toute exception réseau wrappée en SynthesisUnavailableException.
 * Deptrac : Infrastructure → Domain (ArticleContentFetcherInterface, FetchedContent, SynthesisUnavailableException).
 */
final class HttpArticleContentFetcher implements ArticleContentFetcherInterface
{
    private const TIMEOUT = 10.0;
    private const USER_AGENT = 'BrieflyAI/1.0 (+https://briefly.ai/bot)';
    private const PAYWALL_MIN_LENGTH = 500; // caractères

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetche le contenu textuel d'un article.
     *
     * @param string $url URL déjà validée SSRF par SynthesisService
     *
     * @throws SynthesisUnavailableException si HTTP inaccessible ou timeout
     */
    public function fetchContent(string $url): FetchedContent
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                $url,
                [
                    'timeout' => self::TIMEOUT,
                    'headers' => [
                        'User-Agent' => self::USER_AGENT,
                        'Accept' => 'text/html,application/xhtml+xml,*/*',
                    ],
                ],
            );

            $statusCode = $response->getStatusCode();
            $isPartial = false;

            // Détection paywall via code HTTP
            if (\in_array($statusCode, [402, 403], true)) {
                $isPartial = true;
            }

            if ($statusCode >= 500) {
                throw new SynthesisUnavailableException(\sprintf('Article source returned HTTP %d', $statusCode));
            }

            $rawContent = $response->getContent(false);

            // Strip HTML → texte brut
            $text = $this->extractText($rawContent);

            // Détection paywall via longueur du contenu
            if (!$isPartial && \strlen($text) < self::PAYWALL_MIN_LENGTH) {
                $isPartial = true;
            }

            return new FetchedContent(
                text: $text,
                isPartial: $isPartial,
            );
        } catch (SynthesisUnavailableException $e) {
            throw $e;
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('synthesis.content_fetch_transport_error', [
                'error' => $e->getMessage(),
                // PII-safe : pas d'URL dans les logs
            ]);

            throw new SynthesisUnavailableException('Article content fetch transport error: ' . $e->getMessage(), $e);
        } catch (\Throwable $e) {
            $this->logger->warning('synthesis.content_fetch_unexpected_error', [
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw new SynthesisUnavailableException('Article content fetch error: ' . $e->getMessage(), $e);
        }
    }

    /**
     * Extrait le texte brut depuis le HTML.
     *
     * Stratégie simplifiée Sprint 1 :
     * 1. Strip balises <script> et <style> (contenu non pertinent)
     * 2. Décoder les entités HTML
     * 3. strip_tags() pour supprimer toutes les balises restantes
     * 4. Normaliser les espaces blancs
     *
     * Limitation : ne détecte pas les blocs de contenu principal.
     * US-016 (EPIC-002) implémentera une extraction sémantique (readability).
     */
    private function extractText(string $html): string
    {
        // Supprimer les blocs <script> et <style>
        $text = (string) preg_replace(
            '/<(script|style)[^>]*>.*?<\/(script|style)>/si',
            ' ',
            $html,
        );

        // Strip toutes les balises HTML
        $text = strip_tags($text);

        // Décoder les entités HTML
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // Normaliser espaces et sauts de ligne multiples
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
