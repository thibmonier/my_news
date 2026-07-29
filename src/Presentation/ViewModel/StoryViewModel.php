<?php

declare(strict_types=1);

namespace App\Presentation\ViewModel;

use App\Domain\Feed\ArticleCategory;
use App\Domain\Summary\ArticleSummary;

/**
 * ViewModel — Une histoire dans la vue publique du Daily Brief (US-001/T-001-05).
 *
 * DTO de présentation immuable. Découple la couche Presentation des entités Domain.
 * Constitution §4 : jamais d'entité Doctrine dans les templates/vues.
 *
 * position    : formaté en "01", "02", "03" (invariant INV-1).
 * excerpt     : déjà tronqué à 280 chars (logique dans BriefPublicViewRepositoryInterface).
 * sourceUrl   : URL externe tracée (rel="noopener noreferrer" dans le template).
 * articleId   : UUID de l'article — utilisé pour la clé de cache condensé IA (US-004).
 * summary     : condensé IA pré-généré ou null si indisponible (US-004).
 * category    : catégorie éditoriale de l'article (US-005) — badge coloré dans la carte.
 *
 * Couche Presentation — dépend uniquement des types PHP natifs + Domain (ArticleSummary, ArticleCategory).
 * Deptrac : Presentation:[Domain, Application].
 */
final readonly class StoryViewModel
{
    public function __construct(
        /** Position formatée : "01", "02", "03". */
        public readonly string $position,
        /** Titre de l'article. */
        public readonly string $title,
        /** Nom de la source RSS/Atom. */
        public readonly string $sourceName,
        /** Extrait du contenu (≤ 280 caractères). */
        public readonly string $excerpt,
        /** URL externe de l'article original. */
        public readonly string $sourceUrl,
        /** UUID de l'article — clé de cache condensé IA (US-004). */
        public readonly string $articleId = '',
        /** Condensé IA pré-généré (US-004) — null si non disponible. */
        public readonly ?ArticleSummary $summary = null,
        /** Catégorie éditoriale de l'article (US-005) — défaut PRODUCTIVITY. */
        public readonly ArticleCategory $category = ArticleCategory::Productivity,
    ) {
    }
}
