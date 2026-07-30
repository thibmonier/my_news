<?php

declare(strict_types=1);

namespace App\Domain\Brief;

/**
 * Port Domaine — Cache Redis des synthèses narratives du Daily Brief (US-006).
 *
 * Port secondaire (driven) pour le cache éphémère des synthèses Featured Summary.
 * Implémenté par App\Infrastructure\Brief\Cache\RedisFeaturedSummaryCache.
 *
 * Stratégie cache-aside :
 * - Clé  : `briefly:featured_summary:{Y-m-d}` — date du brief (jamais d'UUID utilisateur — RGPD)
 * - TTL  : 86 400 secondes (24h)
 *
 * Sécurité / RGPD :
 * - Aucun PII dans les clés ou valeurs cachées
 * - Fail-safe : en cas d'erreur Redis, FeaturedSummaryService relira depuis la DB
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
interface FeaturedSummaryCacheInterface
{
    /**
     * Récupère une synthèse depuis le cache Redis.
     *
     * @param string $dateKey Date au format Y-m-d (clé de cache)
     *
     * @return FeaturedSummaryDTO|null null si cache miss ou entrée expirée
     */
    public function get(string $dateKey): ?FeaturedSummaryDTO;

    /**
     * Stocke une synthèse dans le cache avec un TTL.
     *
     * @param string $dateKey Date au format Y-m-d (clé de cache)
     * @param FeaturedSummaryDTO $summary Synthèse à mettre en cache
     * @param int $ttl Durée de vie en secondes (86 400 = 24h)
     */
    public function set(string $dateKey, FeaturedSummaryDTO $summary, int $ttl): void;
}
