<?php

declare(strict_types=1);

namespace App\Domain\Brief;

/**
 * Port — Contrat du service de sélection des histoires du Daily Brief.
 *
 * Extrait pour permettre le test unitaire du Handler sans dépendre
 * de la classe finale BriefSelectorService (DIP — Dependency Inversion Principle).
 *
 * Constitution §4 : interfaces dans le Domain, implémentations dans Domain ou Application.
 */
interface BriefSelectorServiceInterface
{
    /**
     * Sélectionne les N meilleures histoires (max 3) pour la date donnée.
     *
     * Effets de bord :
     * - Persiste le DailyBrief via DailyBriefRepositoryInterface::upsertForToday()
     *
     * @return BriefGenerationFailedEvent|null Événement à dispatcher si échec, null si succès
     */
    public function selectTopStories(\DateTimeImmutable $date): ?BriefGenerationFailedEvent;
}
