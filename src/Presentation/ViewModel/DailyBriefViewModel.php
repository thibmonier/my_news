<?php

declare(strict_types=1);

namespace App\Presentation\ViewModel;

use App\Domain\Brief\BriefPublicView;
use App\Domain\Brief\BriefStoryPublicView;
use App\Domain\Summary\ArticleSummary;

/**
 * ViewModel — Vue publique du Daily Brief (US-001/T-001-02).
 *
 * DTO de présentation immuable construit depuis BriefPublicView (domain read model).
 * Contient la logique de formatage des données pour l'affichage HTML.
 *
 * lastUpdatedFormatted : horodatage UTC — format "28 Jul 2026 14:30 UTC" (US-001 conversation §2).
 * stories              : liste triée par position ASC (INV-1 : 01, 02, 03).
 *
 * La logique de troncature (280 chars) est déjà appliquée par le repository.
 * La logique de formatage de la date est dans fromPublicView() (ViewModel, pas template).
 *
 * US-004 : fromPublicView() accepte un tableau optionnel de condensés IA indexés par position.
 *
 * Couche Presentation — dépend de Domain (BriefPublicView) uniquement.
 * Deptrac : Presentation:[Domain, Application].
 */
final readonly class DailyBriefViewModel
{
    /**
     * @param list<StoryViewModel> $stories
     */
    public function __construct(
        /** Horodatage formaté UTC : "28 Jul 2026 14:30 UTC". */
        public readonly string $lastUpdatedFormatted,
        /** @var list<StoryViewModel> */
        public readonly array $stories,
    ) {
    }

    /**
     * Construit le ViewModel depuis le read model Domain.
     *
     * Formatage de la date : "d M Y H:i" + " UTC" (US-001 critère "DD MMM YYYY HH:MM UTC").
     * Conversion en UTC explicite avant formatage.
     *
     * @param array<int, ArticleSummary> $summariesByPosition Condensés IA indexés par position (1, 2, 3) — US-004
     */
    public static function fromPublicView(BriefPublicView $view, array $summariesByPosition = []): self
    {
        $utc = new \DateTimeZone('UTC');
        $updatedAt = $view->updatedAt->setTimezone($utc);

        // Format : "28 Jul 2026 14:30 UTC"
        $lastUpdatedFormatted = $updatedAt->format('d M Y H:i') . ' UTC';

        $stories = array_map(
            static fn (BriefStoryPublicView $s): StoryViewModel => new StoryViewModel(
                position: \sprintf('%02d', $s->position),
                title: $s->articleTitle,
                sourceName: $s->sourceName,
                excerpt: $s->excerpt,
                sourceUrl: $s->articleUrl,
                articleId: $s->articleId,
                summary: $summariesByPosition[$s->position] ?? null,
                category: $s->category,
            ),
            $view->stories,
        );

        return new self(
            lastUpdatedFormatted: $lastUpdatedFormatted,
            stories: $stories,
        );
    }
}
