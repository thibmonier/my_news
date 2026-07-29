<?php

declare(strict_types=1);

namespace App\Application\Summary;

use App\Domain\Summary\ArticleSummary;
use App\Domain\Summary\ArticleSummaryRepositoryInterface;
use App\Domain\Summary\SummaryCacheInterface;
use App\Domain\Summary\SummaryCircuitBreakerInterface;
use App\Domain\Summary\SummaryClientInterface;
use App\Domain\Summary\SummaryUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * Service Application — Orchestration de la génération de condensés IA (US-004).
 *
 * Flux d'exécution (cache-aside + circuit breaker + fallback) :
 * 1. Cache Redis (clé `briefly:summary:{sha256(articleId)}`) → retour immédiat si chaud
 * 2. Vérification circuit breaker Mistral → appel API si fermé
 * 3. Fallback OpenAI si Mistral KO (circuit ouvert ou exception)
 * 4. Dégradé : extrait RSS brut ≤ 280 chars + badge "RÉSUMÉ AUTOMATIQUE INDISPONIBLE"
 *
 * Sécurité RGPD :
 * - articleId (UUID) dans la clé cache — jamais en clair dans les prompts LLM
 * - articleText PII-free (garantie par l'appelant)
 * - Logging : article_id + model_version uniquement (jamais le texte de l'article)
 *
 * Couche Application — dépend uniquement de Domain.
 * Deptrac : Application:[Domain].
 */
final class ArticleSummaryService implements ArticleSummaryServiceInterface
{
    /** TTL cache Redis : 24h (US-004 Conversation §3). */
    private const CACHE_TTL = 86400;

    /** Identifiant du provider Mistral pour le circuit breaker. */
    private const PROVIDER_MISTRAL = 'mistral';

    /** Identifiant du provider OpenAI pour le circuit breaker. */
    private const PROVIDER_OPENAI = 'openai';

    public function __construct(
        private readonly SummaryClientInterface $mistralClient,
        private readonly SummaryClientInterface $openAiClient,
        private readonly SummaryCircuitBreakerInterface $circuitBreaker,
        private readonly SummaryCacheInterface $cache,
        private readonly ArticleSummaryRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * PII-free : $articleText ne doit JAMAIS contenir d'identifiant utilisateur (assertion CI T-004-11).
     */
    public function getSummary(string $articleId, string $articleText): ArticleSummary
    {
        $cacheKey = $this->buildCacheKey($articleId);

        // ── 1. Cache check ────────────────────────────────────────────────────
        $cached = $this->cache->get($cacheKey);

        if (null !== $cached) {
            return $cached;
        }

        // ── 2. Mistral (provider primaire) ────────────────────────────────────
        if (!$this->circuitBreaker->isOpen(self::PROVIDER_MISTRAL)) {
            try {
                $summary = $this->mistralClient->summarize($articleText, $articleId);
                $this->circuitBreaker->recordSuccess(self::PROVIDER_MISTRAL);
                $this->cacheAndPersist($cacheKey, $summary);
                $this->logger->info('summary.cache_miss', [
                    'event' => 'summary.cache_miss',
                    'article_id' => $articleId,
                    'model' => $summary->modelVersion,
                    // PII-safe : jamais le texte de l'article dans les logs
                ]);

                return $summary;
            } catch (SummaryUnavailableException) {
                $this->circuitBreaker->recordFailure(self::PROVIDER_MISTRAL);
            }
        }

        // ── 3. OpenAI (fallback) ──────────────────────────────────────────────
        if (!$this->circuitBreaker->isOpen(self::PROVIDER_OPENAI)) {
            try {
                $summary = $this->openAiClient->summarize($articleText, $articleId);
                $this->circuitBreaker->recordSuccess(self::PROVIDER_OPENAI);
                $this->cacheAndPersist($cacheKey, $summary);

                return $summary;
            } catch (SummaryUnavailableException) {
                $this->circuitBreaker->recordFailure(self::PROVIDER_OPENAI);
            }
        }

        // ── 4. Mode dégradé : extrait RSS brut ──────────────────────────────
        return $this->buildDegradedSummary($articleId, $articleText);
    }

    /**
     * Construit la clé de cache Redis pour un article.
     *
     * Format : `briefly:summary:{sha256(articleId)}`
     * L'UUID de l'article est hashé — RGPD-safe (non réversible).
     */
    private function buildCacheKey(string $articleId): string
    {
        return 'briefly:summary:' . hash('sha256', $articleId);
    }

    /**
     * Stocke le condensé dans Redis et le persiste en PostgreSQL.
     */
    private function cacheAndPersist(string $cacheKey, ArticleSummary $summary): void
    {
        $this->cache->set($cacheKey, $summary, self::CACHE_TTL);

        try {
            $this->repository->save($summary);
        } catch (\Throwable $e) {
            // La persistance DB est secondaire — ne pas bloquer le retour utilisateur
            $this->logger->warning('summary.persist_failed', [
                'event' => 'summary.persist_failed',
                'error' => $e->getMessage(),
                // PII-safe : jamais l'article_id en clair (potentiellement sensible si tracé)
            ]);
        }
    }

    /**
     * Construit un condensé dégradé à partir de l'extrait RSS (≤ 280 chars).
     *
     * Utilisé quand Mistral ET OpenAI sont tous les deux indisponibles.
     * Badge "RÉSUMÉ AUTOMATIQUE INDISPONIBLE" côté vue (US-004 Conversation §4).
     */
    private function buildDegradedSummary(string $articleId, string $articleText): ArticleSummary
    {
        $excerpt = mb_substr(strip_tags($articleText), 0, ArticleSummary::MAX_DEGRADED_CONTENT_LENGTH);

        $this->logger->warning('summary.all_providers_failed', [
            'event' => 'summary.all_providers_failed',
            'article_id' => $articleId,
            // PII-safe : jamais le texte de l'article dans les logs
        ]);

        return new ArticleSummary(
            articleId: $articleId,
            keyPoints: [],
            modelVersion: '',
            createdAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            isDegraded: true,
            degradedContent: $excerpt,
        );
    }
}
