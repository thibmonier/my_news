<?php

declare(strict_types=1);

namespace App\Infrastructure\Synthesis\Cache;

use App\Domain\Synthesis\SynthesisCacheInterface;
use App\Domain\Synthesis\SynthesisResponse;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Adapter Infrastructure — Cache Redis des synthèses IA (US-011 / T-011-05).
 *
 * Implémente SynthesisCacheInterface (port Domain) via Symfony Cache PSR-6.
 * Pool injecté : `cache.synthesis` (Redis, TTL 86 400 s par défaut).
 *
 * Clé de cache : sha256(url . '_' . level) — 3 entrées par URL (une par niveau).
 *
 * Sérialisation :
 * - Format JSON (compact, lisible pour debug Redis)
 * - Fail-safe : toute erreur de désérialisation retourne null (cache miss forcé)
 *
 * RGPD :
 * - Clé cache = sha256(url + level) — l'URL est publique, pas de PII
 * - Valeur = synthèse IA (content, keyPoints, sources) — jamais d'identifiant utilisateur
 *
 * Deptrac : Infrastructure → Domain (SynthesisCacheInterface, SynthesisResponse).
 */
final class RedisSynthesisCache implements SynthesisCacheInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $synthesisPool,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @throws \RuntimeException si Redis est inaccessible (signale un BYPASS au SynthesisService)
     */
    public function get(string $cacheKey): ?SynthesisResponse
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
            // Redis inaccessible — loguer et propager pour que SynthesisService
            // puisse retourner BYPASS et appeler Mistral directement (US-012 T-012-04).
            $this->logger->warning('synthesis.cache_unavailable', [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Cache Redis indisponible : ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $cacheKey, SynthesisResponse $response, int $ttl): void
    {
        try {
            $item = $this->synthesisPool->getItem($cacheKey);
            $item->set($this->serialize($response));
            $item->expiresAfter($ttl);
            $this->synthesisPool->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('synthesis.cache_set_error', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sérialise une SynthesisResponse en JSON.
     */
    private function serialize(SynthesisResponse $response): string
    {
        return json_encode([
            'content' => $response->content,
            'keyPoints' => $response->keyPoints,
            'sources' => $response->sources,
            'originalUrl' => $response->originalUrl,
            'isPartial' => $response->isPartial,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * Désérialise une SynthesisResponse depuis JSON.
     *
     * @return SynthesisResponse|null null si JSON invalide ou données corrompues
     */
    private function deserialize(string $json): ?SynthesisResponse
    {
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

            if (!\is_array($data)) {
                return null;
            }

            /** @var array<string, mixed> $data */
            $content = \is_string($data['content'] ?? null) ? $data['content'] : '';
            $originalUrl = \is_string($data['originalUrl'] ?? null) ? $data['originalUrl'] : '';
            $isPartial = (bool) ($data['isPartial'] ?? false);

            $keyPoints = [];
            $rawPoints = $data['keyPoints'] ?? [];

            if (\is_array($rawPoints)) {
                foreach ($rawPoints as $point) {
                    if (\is_string($point) && '' !== $point) {
                        $keyPoints[] = $point;
                    }
                }
            }

            $sources = [];
            $rawSources = $data['sources'] ?? [];

            if (\is_array($rawSources)) {
                foreach ($rawSources as $source) {
                    if (\is_string($source) && '' !== $source) {
                        $sources[] = $source;
                    }
                }
            }

            return new SynthesisResponse(
                content: $content,
                keyPoints: $keyPoints,
                sources: $sources,
                originalUrl: $originalUrl,
                isPartial: $isPartial,
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
