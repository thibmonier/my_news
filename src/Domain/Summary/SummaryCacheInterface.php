<?php

declare(strict_types=1);

namespace App\Domain\Summary;

/**
 * Port Domaine — Cache Redis des condensés IA (US-004).
 *
 * Port secondaire (driven) pour le cache éphémère des condensés.
 * Implémenté par App\Infrastructure\Summary\Cache\RedisSummaryCache.
 *
 * Stratégie cache-aside :
 * - Clé  : `briefly:summary:{sha256(articleId)}` (jamais l'UUID utilisateur — RGPD)
 * - TTL  : 86 400 secondes (24h) — conforme US-004 Conversation §3
 *
 * Sécurité :
 * - Aucun PII dans les clés ou valeurs cachées
 * - Fail-safe : en cas d'erreur Redis, ArticleSummaryService génère le condensé à la demande
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
interface SummaryCacheInterface
{
    /**
     * Récupère un condensé depuis le cache.
     *
     * @param string $cacheKey Clé de cache (format : `briefly:summary:{sha256(articleId)}`)
     *
     * @return ArticleSummary|null null si cache miss ou entrée expirée
     */
    public function get(string $cacheKey): ?ArticleSummary;

    /**
     * Stocke un condensé dans le cache avec un TTL.
     *
     * @param string $cacheKey Clé de cache
     * @param ArticleSummary $summary Condensé à mettre en cache
     * @param int $ttl Durée de vie en secondes (86400 = 24h)
     */
    public function set(string $cacheKey, ArticleSummary $summary, int $ttl): void;
}
