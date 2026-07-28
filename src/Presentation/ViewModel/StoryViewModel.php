<?php

declare(strict_types=1);

namespace App\Presentation\ViewModel;

/**
 * ViewModel — Une histoire dans la vue publique du Daily Brief (US-001/T-001-05).
 *
 * DTO de présentation immuable. Découple la couche Presentation des entités Domain.
 * Constitution §4 : jamais d'entité Doctrine dans les templates/vues.
 *
 * position    : formaté en "01", "02", "03" (invariant INV-1).
 * excerpt     : déjà tronqué à 280 chars (logique dans BriefPublicViewRepositoryInterface).
 * sourceUrl   : URL externe tracée (rel="noopener noreferrer" dans le template).
 *
 * Couche Presentation — dépend uniquement des types PHP natifs.
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
    ) {
    }
}
