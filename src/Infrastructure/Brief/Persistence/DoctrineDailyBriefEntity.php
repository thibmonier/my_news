<?php

declare(strict_types=1);

namespace App\Infrastructure\Brief\Persistence;

use App\Domain\Brief\DailyBriefStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entité Doctrine — représentation persistée d'un DailyBrief.
 *
 * Réside dans la couche Infrastructure UNIQUEMENT.
 * Les attributs #[ORM] ne doivent JAMAIS apparaître dans src/Domain/ (constitution §4).
 *
 * Table : daily_briefs
 * Contrainte UNIQUE sur date (un seul brief par jour).
 * Index sur status pour récupérer les briefs "ready".
 */
#[ORM\Entity]
#[ORM\Table(name: 'daily_briefs')]
#[ORM\UniqueConstraint(name: 'daily_briefs_date_unique', columns: ['date'])]
#[ORM\Index(name: 'idx_daily_briefs_date', columns: ['date'])]
#[ORM\Index(name: 'idx_daily_briefs_status', columns: ['status'])]
class DoctrineDailyBriefEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** Date du brief (DATE, sans heure) */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 10, enumType: DailyBriefStatus::class)]
    private DailyBriefStatus $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, DoctrineBriefStoryEntity> */
    #[ORM\OneToMany(
        targetEntity: DoctrineBriefStoryEntity::class,
        mappedBy: 'brief',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $stories;

    public function __construct(
        Uuid $id,
        \DateTimeImmutable $date,
        DailyBriefStatus $status,
        \DateTimeImmutable $updatedAt,
    ) {
        $this->id = $id;
        $this->date = $date;
        $this->status = $status;
        $this->updatedAt = $updatedAt;
        $this->stories = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getStatus(): DailyBriefStatus
    {
        return $this->status;
    }

    public function setStatus(DailyBriefStatus $status): void
    {
        $this->status = $status;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    /** @return Collection<int, DoctrineBriefStoryEntity> */
    public function getStories(): Collection
    {
        return $this->stories;
    }

    public function clearStories(): void
    {
        $this->stories->clear();
    }

    public function addStory(DoctrineBriefStoryEntity $story): void
    {
        $this->stories->add($story);
    }
}
