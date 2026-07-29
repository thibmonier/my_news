<?php

declare(strict_types=1);

namespace App\Infrastructure\Brief\Persistence;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entité Doctrine — représentation persistée d'une BriefStory.
 *
 * Réside dans la couche Infrastructure UNIQUEMENT.
 * Les attributs #[ORM] ne doivent JAMAIS apparaître dans src/Domain/ (constitution §4).
 *
 * Table : brief_stories
 * Contrainte UNIQUE sur (brief_id, position) : pas de doublon de position dans un brief.
 * Index sur article_id pour les jointures.
 */
#[ORM\Entity]
#[ORM\Table(name: 'brief_stories')]
#[ORM\UniqueConstraint(name: 'brief_stories_brief_position_unique', columns: ['brief_id', 'position'])]
#[ORM\Index(name: 'idx_brief_stories_article_id', columns: ['article_id'])]
class DoctrineBriefStoryEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: DoctrineDailyBriefEntity::class, inversedBy: 'stories')]
    #[ORM\JoinColumn(name: 'brief_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private DoctrineDailyBriefEntity $brief;

    /** UUID de l'article sélectionné (stocké sans FK stricte pour éviter les couplages de migration) */
    #[ORM\Column(type: 'uuid')]
    private Uuid $articleId;

    /** Position de présentation : 1 (lead story), 2, 3 */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => false])]
    private int $position;

    /** Score composite calculé lors de la sélection (pour analytics EPIC-008) */
    #[ORM\Column(type: 'float')]
    private float $selectionScore;

    public function __construct(
        Uuid $id,
        DoctrineDailyBriefEntity $brief,
        Uuid $articleId,
        int $position,
        float $selectionScore,
    ) {
        $this->id = $id;
        $this->brief = $brief;
        $this->articleId = $articleId;
        $this->position = $position;
        $this->selectionScore = $selectionScore;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBrief(): DoctrineDailyBriefEntity
    {
        return $this->brief;
    }

    public function getArticleId(): Uuid
    {
        return $this->articleId;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getSelectionScore(): float
    {
        return $this->selectionScore;
    }
}
