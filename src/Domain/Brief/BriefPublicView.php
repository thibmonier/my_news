<?php

declare(strict_types=1);

namespace App\Domain\Brief;

/**
 * Read model — Vue d'affichage publique du Daily Brief.
 *
 * DTO immuable construit par BriefPublicViewRepositoryInterface.
 * PHP pur — AUCUN import Symfony/Doctrine/ApiPlatform.
 * Constitution §4 : entités Domain = classes PHP pures.
 *
 * Contient toutes les données nécessaires à la page /brief (US-001) :
 * - horodatage updated_at (UTC) pour "LAST UPDATED DD MMM YYYY HH:MM UTC"
 * - 3 stories enrichies (titre + source + extrait + URL)
 */
final readonly class BriefPublicView
{
    /**
     * @param list<BriefStoryPublicView> $stories stories triées par position ASC
     */
    public function __construct(
        /** Horodatage de la dernière mise à jour du brief (UTC). */
        public readonly \DateTimeImmutable $updatedAt,
        /** @var list<BriefStoryPublicView> */
        public readonly array $stories,
    ) {
    }
}
