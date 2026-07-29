<?php

declare(strict_types=1);

namespace App\Domain\Brief;

use App\Domain\Feed\Article;

/**
 * Port secondaire — Lecture des articles candidats pour la sélection du brief.
 *
 * Ségrégation ISP : interface spécialisée pour la lecture des articles
 * dans le contexte de la génération de briefs, distincte de ArticleRepositoryInterface
 * (qui gère l'ingestion RSS).
 *
 * Constitution §4 : interfaces dans le Domain, implémentations dans Infrastructure (DIP).
 * Deptrac Domain:[] — aucune dépendance framework dans ce fichier.
 */
interface ArticleCandidateRepositoryInterface
{
    /**
     * Retourne les articles candidats pour la sélection du brief.
     *
     * Critères :
     * - publiés depuis $since (en pratique : NOW() - 24h)
     * - is_full_text_accessible = true
     * - triés par published_at DESC puis source_id (déterminisme)
     *
     * Limite SQL raisonnable (200 articles max) pour borner la mémoire.
     *
     * @param \DateTimeImmutable $since Plancher de publication
     *
     * @return list<Article>
     */
    public function findCandidatesForBrief(\DateTimeImmutable $since): array;
}
