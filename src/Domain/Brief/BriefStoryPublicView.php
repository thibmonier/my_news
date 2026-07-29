<?php

declare(strict_types=1);

namespace App\Domain\Brief;

use App\Domain\Feed\ArticleCategory;

/**
 * Read model — Une histoire du Daily Brief avec données d'affichage.
 *
 * DTO immuable construit par BriefPublicViewRepositoryInterface pour la présentation.
 * PHP pur — AUCUN import Symfony/Doctrine/ApiPlatform.
 * Constitution §4 : entités Domain = classes PHP pures.
 *
 * excerpt  : raw_content tronqué à 280 caractères par le repository (pas dans le template).
 * category : catégorie éditoriale de l'article persistée en base (US-005).
 *            Valeur par défaut PRODUCTIVITY si non renseignée (articles antérieurs).
 * Notes techniques US-001/T-001-05, US-005/T-005-05.
 */
final readonly class BriefStoryPublicView
{
    public function __construct(
        /** Position de présentation : 1 (lead), 2, 3. */
        public readonly int $position,
        /** Titre de l'article sélectionné. */
        public readonly string $articleTitle,
        /** URL de l'article source (lien OUVRIR L'ORIGINAL). */
        public readonly string $articleUrl,
        /** Extrait du raw_content tronqué à 280 caractères. */
        public readonly string $excerpt,
        /** Nom de la source RSS/Atom. */
        public readonly string $sourceName,
        /** UUID de l'article (clé de cache condensé IA — US-004). */
        public readonly string $articleId = '',
        /** Contenu brut de l'article pour la génération du condensé IA (US-004). */
        public readonly string $rawContent = '',
        /** Catégorie éditoriale de l'article (US-005) — défaut PRODUCTIVITY. */
        public readonly ArticleCategory $category = ArticleCategory::Productivity,
    ) {
    }
}
