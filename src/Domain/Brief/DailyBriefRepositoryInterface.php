<?php

declare(strict_types=1);

namespace App\Domain\Brief;

/**
 * Port secondaire — Persistence des DailyBriefs.
 *
 * Définit le contrat de repository pour les Daily Briefs.
 * L'implémentation concrète réside dans Infrastructure (Doctrine + DBAL).
 *
 * Constitution §4 : interfaces dans le Domain, implémentations dans Infrastructure (DIP).
 * Deptrac Domain:[] — aucune dépendance framework dans ce fichier.
 */
interface DailyBriefRepositoryInterface
{
    /**
     * Retourne le DailyBrief pour la date donnée, ou null s'il n'existe pas encore.
     *
     * @param \DateTimeImmutable $date Date du brief (heure ignorée)
     */
    public function findForDate(\DateTimeImmutable $date): ?DailyBrief;

    /**
     * Crée ou met à jour le DailyBrief pour la date cible (idempotence).
     *
     * Garantie : INSERT … ON CONFLICT (date) DO UPDATE.
     * Les BriefStories liées sont supprimées puis réinsérées dans la même transaction.
     *
     * @param DailyBrief $brief Brief avec les stories sélectionnées
     */
    public function upsertForToday(DailyBrief $brief): void;

    /**
     * Retourne le DailyBrief le plus récent avec status = 'ready'.
     *
     * Algorithme : date la plus récente en premier (JOIN FETCH sur BriefStories).
     * Utilisé par la couche Application pour accéder au brief courant
     * sans connaître la date exacte.
     *
     * @return DailyBrief|null null si aucun brief en état 'ready' (table vide ou tous en error/pending)
     */
    public function findLatest(): ?DailyBrief;
}
