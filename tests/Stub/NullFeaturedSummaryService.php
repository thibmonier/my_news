<?php

declare(strict_types=1);

namespace Tests\Stub;

use App\Application\Brief\FeaturedSummary\FeaturedSummaryServiceInterface;
use App\Domain\Brief\BriefStoryPublicView;
use App\Domain\Brief\FeaturedSummaryDTO;

/**
 * Stub test — FeaturedSummaryServiceInterface qui retourne toujours null.
 *
 * Enregistré par défaut dans config/packages/test/services.yaml pour éviter
 * que le FeaturedSummaryService réel (Redis) n'affecte les tests qui ne le mockent pas.
 *
 * Les tests qui nécessitent un featured summary (FeaturedSummaryBriefTest) écrasent
 * ce stub via static::getContainer()->set(FeaturedSummaryServiceInterface::class, ...).
 */
final class NullFeaturedSummaryService implements FeaturedSummaryServiceInterface
{
    public function getForToday(\DateTimeImmutable $now): ?FeaturedSummaryDTO
    {
        return null;
    }

    /**
     * @param list<BriefStoryPublicView> $stories
     */
    public function generateForBrief(string $briefId, \DateTimeImmutable $date, array $stories): FeaturedSummaryDTO
    {
        throw new \LogicException('NullFeaturedSummaryService::generateForBrief() should not be called in tests.');
    }
}
