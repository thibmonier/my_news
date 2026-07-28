<?php

declare(strict_types=1);

use App\Domain\Feed\FeedType;
use App\Domain\Feed\Source;
use App\Domain\Feed\SourceStatus;

/*
 * Unit tests — Source (entité domaine)
 *
 * Tests purement unitaires : aucune dépendance framework, aucun I/O.
 * Vérifie construction, getters, et statut actif/inactif.
 */

test('Source expose correctement ses propriétés de base', function (): void {
    $source = new Source(
        id: 'abc-123',
        name: 'TechCrunch',
        url: 'https://techcrunch.com/feed/',
        feedType: FeedType::Rss,
        status: SourceStatus::Active,
    );

    expect($source->getId())->toBe('abc-123')
        ->and($source->getName())->toBe('TechCrunch')
        ->and($source->getUrl())->toBe('https://techcrunch.com/feed/')
        ->and($source->getFeedType())->toBe(FeedType::Rss)
        ->and($source->getStatus())->toBe(SourceStatus::Active)
        ->and($source->isActive())->toBeTrue();
});

test('Source inactive retourne isActive() false', function (): void {
    $source = new Source(
        id: 'xyz',
        name: 'Inactive Feed',
        url: 'https://example.com/feed',
        feedType: FeedType::Atom,
        status: SourceStatus::Inactive,
    );

    expect($source->isActive())->toBeFalse();
});

test('Source lastFetchedAt est null par défaut', function (): void {
    $source = new Source(
        id: 'id-1',
        name: 'Feed',
        url: 'https://example.com',
        feedType: FeedType::Rss,
        status: SourceStatus::Active,
    );

    expect($source->getLastFetchedAt())->toBeNull()
        ->and($source->getLastErrorAt())->toBeNull();
});

test('Source expose lastFetchedAt et lastErrorAt fournis', function (): void {
    $fetchedAt = new DateTimeImmutable('2026-07-28 10:00:00', new DateTimeZone('UTC'));
    $errorAt = new DateTimeImmutable('2026-07-28 09:00:00', new DateTimeZone('UTC'));

    $source = new Source(
        id: 'id-2',
        name: 'Feed',
        url: 'https://example.com',
        feedType: FeedType::Rss,
        status: SourceStatus::Active,
        lastFetchedAt: $fetchedAt,
        lastErrorAt: $errorAt,
    );

    expect($source->getLastFetchedAt())->toBe($fetchedAt)
        ->and($source->getLastErrorAt())->toBe($errorAt);
});
