<?php

declare(strict_types=1);

namespace App\Domain\Brief;

/**
 * Read model — Une histoire du Daily Brief avec données d'affichage.
 *
 * DTO immuable construit par BriefPublicViewRepositoryInterface pour la présentation.
 * PHP pur — AUCUN import Symfony/Doctrine/ApiPlatform.
 * Constitution §4 : entités Domain = classes PHP pures.
 *
 * excerpt : raw_content tronqué à 280 caractères par le repository (pas dans le template).
 * Notes techniques US-001/T-001-05.
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
    ) {
    }
}
