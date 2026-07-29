<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Port Domaine — Cache Redis des synthèses IA (US-011 / T-011-05).
 *
 * Port secondaire (driven) pour le cache éphémère des synthèses Mistral.
 * Implémenté par App\Infrastructure\Synthesis\Cache\RedisSynthesisCache.
 *
 * Stratégie cache-aside :
 * - Clé  : sha256(url . '_' . level) — 3 clés distinctes par URL, une par niveau
 * - TTL  : 86 400 secondes (24 h)
 *
 * Sécurité / RGPD :
 * - Aucun PII dans les clés ou valeurs cachées
 * - L'URL est utilisée directement dans la clé (c'est une URL publique — pas de PII)
 * - Fail-safe : en cas d'erreur Redis, SynthesisService appelle Mistral directement
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
interface SynthesisCacheInterface
{
    /**
     * Récupère une synthèse depuis le cache.
     *
     * @param string $cacheKey Clé de cache (format : sha256(url . '_' . level))
     *
     * @return SynthesisResponse|null null si cache miss ou entrée expirée
     */
    public function get(string $cacheKey): ?SynthesisResponse;

    /**
     * Stocke une synthèse dans le cache avec un TTL.
     *
     * @param string $cacheKey Clé de cache
     * @param SynthesisResponse $response Synthèse à mettre en cache
     * @param int $ttl Durée de vie en secondes (86 400 = 24 h)
     */
    public function set(string $cacheKey, SynthesisResponse $response, int $ttl): void;
}
