<?php

declare(strict_types=1);

namespace App\Infrastructure\Summary\Cache;

use App\Domain\Summary\ArticleSummary;
use App\Domain\Summary\SummaryCacheInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Adapter Infrastructure — Cache Redis des condensés IA (US-004).
 *
 * Implémente SummaryCacheInterface (port Domain) via Symfony Cache PSR-6.
 * Pool injecté : `cache.synthesis` (Redis, TTL 86400s défaut, tags activés).
 *
 * Sérialisation :
 * - Format JSON (compact, lisible pour debug Redis)
 * - Fail-safe : toute erreur de désérialisation retourne null (cache miss forcé)
 *
 * RGPD :
 * - Clé cache = sha256(articleId) — non réversible, pas de PII
 * - Valeur = condensé IA (keyPoints, modelVersion) — jamais d'identifiant utilisateur
 *
 * Deptrac : Infrastructure → Domain (SummaryCacheInterface, ArticleSummary).
 */
final class RedisSummaryCache implements SummaryCacheInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $synthesisPool,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $cacheKey): ?ArticleSummary
    {
        try {
            $item = $this->synthesisPool->getItem($cacheKey);

            if (!$item->isHit()) {
                return null;
            }

            $value = $item->get();

            if (!\is_string($value) || '' === $value) {
                return null;
            }

            return $this->deserialize($value);
        } catch (\Throwable $e) {
            $this->logger->warning('summary.cache_get_error', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $cacheKey, ArticleSummary $summary, int $ttl): void
    {
        try {
            $item = $this->synthesisPool->getItem($cacheKey);
            $item->set($this->serialize($summary));
            $item->expiresAfter($ttl);
            $this->synthesisPool->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('summary.cache_set_error', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sérialise un condensé en JSON.
     */
    private function serialize(ArticleSummary $summary): string
    {
        return json_encode([
            'articleId' => $summary->articleId,
            'keyPoints' => $summary->keyPoints,
            'modelVersion' => $summary->modelVersion,
            'createdAt' => $summary->createdAt->format(\DateTimeInterface::ATOM),
            'isDegraded' => $summary->isDegraded,
            'degradedContent' => $summary->degradedContent,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * Désérialise un condensé depuis JSON.
     *
     * @return ArticleSummary|null null si JSON invalide ou données corrompues
     */
    private function deserialize(string $json): ?ArticleSummary
    {
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

            if (!\is_array($data)) {
                return null;
            }

            /** @var array<string, mixed> $data */
            $articleId = \is_string($data['articleId'] ?? null) ? $data['articleId'] : '';
            $modelVersion = \is_string($data['modelVersion'] ?? null) ? $data['modelVersion'] : '';
            $isDegraded = (bool) ($data['isDegraded'] ?? false);
            $degradedContent = \is_string($data['degradedContent'] ?? null) ? $data['degradedContent'] : '';

            $keyPoints = [];
            $rawPoints = $data['keyPoints'] ?? [];

            if (\is_array($rawPoints)) {
                foreach ($rawPoints as $point) {
                    if (\is_string($point) && '' !== $point) {
                        $keyPoints[] = $point;
                    }
                }
            }

            $createdAtStr = \is_string($data['createdAt'] ?? null) ? $data['createdAt'] : 'now';
            $createdAt = new \DateTimeImmutable($createdAtStr, new \DateTimeZone('UTC'));

            return new ArticleSummary(
                articleId: $articleId,
                keyPoints: $keyPoints,
                modelVersion: $modelVersion,
                createdAt: $createdAt,
                isDegraded: $isDegraded,
                degradedContent: $degradedContent,
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
