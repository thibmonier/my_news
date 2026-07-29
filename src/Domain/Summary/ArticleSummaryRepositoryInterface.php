<?php

declare(strict_types=1);

namespace App\Domain\Summary;

/**
 * Port Domaine — Persistance des condensés IA d'articles (US-004).
 *
 * Port secondaire (driven) pour la persistance des condensés IA.
 * Implémenté par App\Infrastructure\Summary\Persistence\DoctrineArticleSummaryRepository.
 *
 * La persistance est assurée en complément du cache Redis :
 * - Redis (TTL 24h) : cache chaud éphémère
 * - PostgreSQL (article_summaries) : persistance analytique + warm-up potentiel
 *
 * RGPD : article_id est un UUID non-séquentiel (non réversible vers données personnelles).
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
interface ArticleSummaryRepositoryInterface
{
    /**
     * Recherche un condensé persisté pour un article donné.
     *
     * @param string $articleId UUID de l'article
     *
     * @return ArticleSummary|null null si aucun condensé en base
     */
    public function findByArticleId(string $articleId): ?ArticleSummary;

    /**
     * Persiste un condensé IA en base de données.
     *
     * @param ArticleSummary $summary Condensé validé (invariants respectés par le VO)
     */
    public function save(ArticleSummary $summary): void;
}
