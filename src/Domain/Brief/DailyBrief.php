<?php

declare(strict_types=1);

namespace App\Domain\Brief;

/**
 * Entité domaine — Daily Brief du jour.
 *
 * PHP pur — AUCUN attribut #[ORM], AUCUN import Symfony/Doctrine/ApiPlatform.
 * Constitution §4 : entités Domain = classes PHP pures.
 *
 * Un DailyBrief est identifié par sa date (unicité : un seul brief par jour).
 * Il agrège jusqu'à 3 BriefStories sélectionnées algorithmiquement.
 */
final class DailyBrief
{
    /** @param list<BriefStory> $stories */
    public function __construct(
        private readonly string $id,
        private readonly \DateTimeImmutable $date,
        private DailyBriefStatus $status,
        private \DateTimeImmutable $updatedAt,
        private array $stories = [],
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    /** Date du brief (composante date uniquement, sans heure). */
    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getStatus(): DailyBriefStatus
    {
        return $this->status;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<BriefStory> */
    public function getStories(): array
    {
        return $this->stories;
    }

    /**
     * Remplace les stories sélectionnées et passe le statut à "ready".
     * Met à jour updated_at pour traçabilité (idempotence).
     *
     * @param list<BriefStory> $stories
     */
    public function applySelection(array $stories, \DateTimeImmutable $updatedAt): void
    {
        $this->stories = $stories;
        $this->status = DailyBriefStatus::Ready;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Marque le brief comme en erreur (0 articles disponibles).
     */
    public function markError(\DateTimeImmutable $updatedAt): void
    {
        $this->status = DailyBriefStatus::Error;
        $this->updatedAt = $updatedAt;
    }

    /** Retourne true si le brief est consultable (contient au moins 1 story). */
    public function isReady(): bool
    {
        return DailyBriefStatus::Ready === $this->status;
    }
}
