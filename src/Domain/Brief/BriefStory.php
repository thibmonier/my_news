<?php

declare(strict_types=1);

namespace App\Domain\Brief;

/**
 * Value Object / Entité domaine — une histoire (article) sélectionnée dans un Daily Brief.
 *
 * PHP pur — AUCUN attribut #[ORM], AUCUN import Symfony/Doctrine/ApiPlatform.
 * Constitution §4 : entités Domain = classes PHP pures.
 *
 * position      : 1, 2 ou 3 (ordre de présentation)
 * selectionScore: score composite calculé par BriefSelectorService (persisté pour analytics EPIC-008)
 */
final class BriefStory
{
    public function __construct(
        private readonly string $id,
        private readonly string $briefId,
        private readonly string $articleId,
        private readonly int $position,
        private readonly float $selectionScore,
    ) {
        if ($position < 1 || $position > 3) {
            throw new \InvalidArgumentException(\sprintf('BriefStory position must be between 1 and 3, got %d.', $position));
        }
        if ($selectionScore < 0.0) {
            throw new \InvalidArgumentException(\sprintf('BriefStory selectionScore must be >= 0, got %f.', $selectionScore));
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBriefId(): string
    {
        return $this->briefId;
    }

    public function getArticleId(): string
    {
        return $this->articleId;
    }

    /** Position de présentation : 1 (lead), 2, 3. */
    public function getPosition(): int
    {
        return $this->position;
    }

    /** Score composite calculé lors de la sélection (pour analytics futures). */
    public function getSelectionScore(): float
    {
        return $this->selectionScore;
    }
}
