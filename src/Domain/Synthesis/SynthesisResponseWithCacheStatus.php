<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Value Object Domaine — Synthèse IA enrichie du statut de cache (US-012).
 *
 * Wraps une SynthesisResponse avec le statut de cache au moment de la génération :
 *   - HIT    : servie depuis Redis (aucun appel Mistral)
 *   - MISS   : générée par Mistral et mise en cache Redis
 *   - BYPASS : Redis indisponible, générée par Mistral sans mise en cache
 *
 * Exposé via le header HTTP `X-Cache` par UrlSynthesisProcessor (US-012 T-012-04).
 *
 * Sécurité / RGPD :
 *   - Aucun PII dans cette classe (le statut de cache est purement technique)
 *   - L'url_hash associé est loggué par SynthesisService (jamais l'URL brute)
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
final class SynthesisResponseWithCacheStatus
{
    /** Cache hit : synthèse servie depuis Redis, aucun appel Mistral. */
    public const HIT = 'HIT';

    /** Cache miss : synthèse générée par Mistral et écrite en cache. */
    public const MISS = 'MISS';

    /** Cache bypass : Redis indisponible, synthèse générée sans cache. */
    public const BYPASS = 'BYPASS';

    /**
     * @param SynthesisResponse $response La synthèse IA générée ou servie depuis le cache
     * @param string $cacheStatus Statut de cache — 'HIT', 'MISS' ou 'BYPASS'
     */
    public function __construct(
        public readonly SynthesisResponse $response,
        public readonly string $cacheStatus,
    ) {
    }
}
