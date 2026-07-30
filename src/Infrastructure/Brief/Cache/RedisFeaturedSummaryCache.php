<?php

declare(strict_types=1);

namespace App\Infrastructure\Brief\Cache;

use App\Domain\Brief\FeaturedSummaryCacheInterface;
use App\Domain\Brief\FeaturedSummaryDTO;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Adapter Infrastructure — Cache Redis des synthèses narratives Featured Summary (US-006).
 *
 * Implémente FeaturedSummaryCacheInterface (port Domain).
 *
 * Clé cache : `briefly:featured_summary:{Y-m-d}` — date du brief (jamais d'UUID utilisateur).
 * TTL : 86 400 secondes (24h) — aligné sur le cycle du batch (5h UTC).
 *
 * Fail-safe : toute erreur Redis est loguée + swallowed. FeaturedSummaryService
 * relira depuis la DB en cas d'indisponibilité Redis.
 *
 * Sérialisation : JSON (compatible avec le pattern existant SynthesisCache/SummaryCache).
 * Les timestamps sont sérialisés en ISO 8601 (DateTimeImmutable::RFC3339).
 *
 * RGPD : aucun PII dans les clés ou valeurs cachées.
 *
 * Deptrac : Infrastructure → Domain (FeaturedSummaryCacheInterface, FeaturedSummaryDTO).
 */
final class RedisFeaturedSummaryCache implements FeaturedSummaryCacheInterface
{
    /** Préfixe de la clé de cache Redis. */
    private const KEY_PREFIX = 'briefly:featured_summary:';

    public function __construct(
        private readonly CacheItemPoolInterface $synthesisPool,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $dateKey): ?FeaturedSummaryDTO
    {
        try {
            $item = $this->synthesisPool->getItem($this->buildKey($dateKey));

            if (!$item->isHit()) {
                return null;
            }

            $value = $item->get();

            if (!\is_string($value) || '' === $value) {
                return null;
            }

            return $this->deserialize($value);
        } catch (\Throwable $e) {
            $this->logger->warning('featured_summary.cache_read_error', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $dateKey, FeaturedSummaryDTO $summary, int $ttl): void
    {
        try {
            $item = $this->synthesisPool->getItem($this->buildKey($dateKey));
            $item->set($this->serialize($summary));
            $item->expiresAfter($ttl);
            $this->synthesisPool->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('featured_summary.cache_write_error', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Construit la clé Redis complète.
     *
     * Format : `briefly:featured_summary:{Y-m-d}`
     * Clé sanitisée pour PSR-6 (replacement des caractères non autorisés).
     */
    private function buildKey(string $dateKey): string
    {
        // PSR-6 interdit les caractères {}()/\@: — on les remplace par _
        return str_replace(':', '_', self::KEY_PREFIX . $dateKey);
    }

    /**
     * Sérialise un FeaturedSummaryDTO en JSON.
     */
    private function serialize(FeaturedSummaryDTO $summary): string
    {
        return json_encode([
            'brief_id' => $summary->briefId,
            'content' => $summary->content,
            'model_version' => $summary->modelVersion,
            'generated_at' => $summary->generatedAt->format(\DateTimeInterface::RFC3339),
            'is_fallback' => $summary->isFallback,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * Désérialise un JSON en FeaturedSummaryDTO.
     *
     * @return FeaturedSummaryDTO|null null si JSON malformé ou champs manquants
     */
    private function deserialize(string $json): ?FeaturedSummaryDTO
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

            if (!\is_array($data)) {
                return null;
            }

            $briefId = \is_string($data['brief_id'] ?? null) ? $data['brief_id'] : '';
            $content = \is_string($data['content'] ?? null) ? $data['content'] : '';
            $modelVersion = \is_string($data['model_version'] ?? null) ? $data['model_version'] : '';
            $isFallback = (bool) ($data['is_fallback'] ?? false);

            $generatedAtRaw = $data['generated_at'] ?? null;
            $generatedAt = \is_string($generatedAtRaw)
                ? new \DateTimeImmutable($generatedAtRaw, new \DateTimeZone('UTC'))
                : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if ('' === $briefId || '' === $content) {
                return null;
            }

            return new FeaturedSummaryDTO(
                briefId: $briefId,
                content: $content,
                modelVersion: $modelVersion,
                generatedAt: $generatedAt,
                isFallback: $isFallback,
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
