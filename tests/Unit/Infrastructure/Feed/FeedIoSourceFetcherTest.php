<?php

declare(strict_types=1);

use App\Domain\Feed\FeedType;
use App\Domain\Feed\Source;
use App\Domain\Feed\SourceStatus;
use App\Infrastructure\Feed\Fetcher\FeedIoSourceFetcher;
use FeedIo\Feed;
use FeedIo\Feed\Item;
use FeedIo\FeedIo;
use FeedIo\Reader\Result;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/*
 * Unit tests — FeedIoSourceFetcher (Infrastructure adapter)
 *
 * Couvre :
 * - RSS valide → ArticleDTO[] correct (title, url, content_hash, publishedAt)
 * - URL normalisée : suppression UTM + trailing slash
 * - SHA-256 calculé sur l'URL canonique
 * - URL source invalide (schéma file://) → RuntimeException (SSRF)
 * - Items sans URL ignorés
 *
 * Note : FeedIo\Reader\Result a un constructeur complexe → mocké via createMock().
 *        Les fermetures de test sont liées à TestCase (uses) pour accès à $this->createMock().
 */
uses(TestCase::class);

/** Construit une Source de test. */
function buildSource(string $url = 'https://techcrunch.com/feed/'): Source
{
    return new Source(
        id: 'src-uuid-001',
        name: 'TechCrunch',
        url: $url,
        feedType: FeedType::Rss,
        status: SourceStatus::Active,
    );
}

/**
 * Construit un objet Feed FeedIo peuplé des items donnés.
 *
 * @param array<int, array{title?: string, link?: string, summary?: string, lastModified?: DateTime}> $items
 */
function buildFeed(array $items): Feed
{
    $feed = new Feed();

    foreach ($items as $itemData) {
        $item = new Item();

        if (isset($itemData['title'])) {
            $item->setTitle($itemData['title']);
        }

        if (isset($itemData['link'])) {
            $item->setLink($itemData['link']);
        }

        if (isset($itemData['summary'])) {
            $item->setSummary($itemData['summary']);
        }

        if (isset($itemData['lastModified'])) {
            $item->setLastModified($itemData['lastModified']);
        }

        $feed->add($item);
    }

    return $feed;
}

// ── Tests ──────────────────────────────────────────────────────────────────

test('fetch retourne des ArticleDTO valides pour un flux RSS avec articles', function (): void {
    $feed = buildFeed([
        [
            'title' => 'Article One',
            'link' => 'https://techcrunch.com/2026/07/28/article-one/',
            'summary' => 'Content of article one',
            'lastModified' => new DateTime('2026-07-28 10:00:00', new DateTimeZone('UTC')),
        ],
        [
            'title' => 'Article Two',
            'link' => 'https://techcrunch.com/2026/07/28/article-two/',
        ],
    ]);

    /** @var PHPUnit\Framework\MockObject\MockObject&Result $resultMock */
    $resultMock = $this->createMock(Result::class);
    $resultMock->method('getFeed')->willReturn($feed);

    /** @var PHPUnit\Framework\MockObject\MockObject&FeedIo $feedIoMock */
    $feedIoMock = $this->createMock(FeedIo::class);
    $feedIoMock->method('read')->willReturn($resultMock);

    $fetcher = new FeedIoSourceFetcher($feedIoMock, new NullLogger());
    $articles = $fetcher->fetch(buildSource());

    expect($articles)->toHaveCount(2)
        ->and($articles[0]->title)->toBe('Article One')
        ->and($articles[0]->url)->toBe('https://techcrunch.com/2026/07/28/article-one/')
        ->and($articles[0]->rawContent)->toBe('Content of article one')
        ->and($articles[0]->contentHash->getValue())->toHaveLength(64)
        ->and(ctype_xdigit($articles[0]->contentHash->getValue()))->toBeTrue()
        ->and($articles[0]->publishedAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($articles[0]->sourceId)->toBe('src-uuid-001');
});

test('fetch normalise les URLs en supprimant les paramètres UTM', function (): void {
    $feed = buildFeed([
        [
            'title' => 'UTM Article',
            'link' => 'https://techcrunch.com/2026/07/28/article?utm_source=newsletter&utm_medium=email',
        ],
    ]);

    /** @var PHPUnit\Framework\MockObject\MockObject&Result $resultMock */
    $resultMock = $this->createMock(Result::class);
    $resultMock->method('getFeed')->willReturn($feed);

    /** @var PHPUnit\Framework\MockObject\MockObject&FeedIo $feedIoMock */
    $feedIoMock = $this->createMock(FeedIo::class);
    $feedIoMock->method('read')->willReturn($resultMock);

    $fetcher = new FeedIoSourceFetcher($feedIoMock, new NullLogger());
    $articles = $fetcher->fetch(buildSource());

    expect($articles)->toHaveCount(1)
        ->and($articles[0]->canonicalUrl)->toBe('https://techcrunch.com/2026/07/28/article');
});

test('fetch normalise les URLs en supprimant le trailing slash', function (): void {
    $feed = buildFeed([
        ['title' => 'Trailing Slash', 'link' => 'https://techcrunch.com/2026/07/28/article/'],
    ]);

    /** @var PHPUnit\Framework\MockObject\MockObject&Result $resultMock */
    $resultMock = $this->createMock(Result::class);
    $resultMock->method('getFeed')->willReturn($feed);

    /** @var PHPUnit\Framework\MockObject\MockObject&FeedIo $feedIoMock */
    $feedIoMock = $this->createMock(FeedIo::class);
    $feedIoMock->method('read')->willReturn($resultMock);

    $fetcher = new FeedIoSourceFetcher($feedIoMock, new NullLogger());
    $articles = $fetcher->fetch(buildSource());

    expect($articles)->toHaveCount(1)
        ->and($articles[0]->canonicalUrl)->toBe('https://techcrunch.com/2026/07/28/article');
});

test('fetch calcule des content_hash SHA-256 distincts pour des URLs canoniques différentes', function (): void {
    $feed = buildFeed([
        ['title' => 'A', 'link' => 'https://example.com/a'],
        ['title' => 'B', 'link' => 'https://example.com/b'],
    ]);

    /** @var PHPUnit\Framework\MockObject\MockObject&Result $resultMock */
    $resultMock = $this->createMock(Result::class);
    $resultMock->method('getFeed')->willReturn($feed);

    /** @var PHPUnit\Framework\MockObject\MockObject&FeedIo $feedIoMock */
    $feedIoMock = $this->createMock(FeedIo::class);
    $feedIoMock->method('read')->willReturn($resultMock);

    $fetcher = new FeedIoSourceFetcher($feedIoMock, new NullLogger());
    $articles = $fetcher->fetch(buildSource());

    expect($articles[0]->contentHash->equals($articles[1]->contentHash))->toBeFalse();
});

test('fetch calcule le même content_hash pour des URLs avec UTM différents vers la même ressource', function (): void {
    $base = 'https://techcrunch.com/2026/07/28/dedup-test';

    $feed = buildFeed([
        ['title' => 'A', 'link' => $base . '?utm_source=twitter'],
        ['title' => 'B', 'link' => $base . '?utm_source=facebook'],
    ]);

    /** @var PHPUnit\Framework\MockObject\MockObject&Result $resultMock */
    $resultMock = $this->createMock(Result::class);
    $resultMock->method('getFeed')->willReturn($feed);

    /** @var PHPUnit\Framework\MockObject\MockObject&FeedIo $feedIoMock */
    $feedIoMock = $this->createMock(FeedIo::class);
    $feedIoMock->method('read')->willReturn($resultMock);

    $fetcher = new FeedIoSourceFetcher($feedIoMock, new NullLogger());
    $articles = $fetcher->fetch(buildSource());

    expect($articles[0]->contentHash->equals($articles[1]->contentHash))->toBeTrue();
});

test('fetch ignore les items sans URL (link null)', function (): void {
    $feed = buildFeed([
        ['title' => 'No Link'],
        ['title' => 'Has Link', 'link' => 'https://techcrunch.com/article'],
    ]);

    /** @var PHPUnit\Framework\MockObject\MockObject&Result $resultMock */
    $resultMock = $this->createMock(Result::class);
    $resultMock->method('getFeed')->willReturn($feed);

    /** @var PHPUnit\Framework\MockObject\MockObject&FeedIo $feedIoMock */
    $feedIoMock = $this->createMock(FeedIo::class);
    $feedIoMock->method('read')->willReturn($resultMock);

    $fetcher = new FeedIoSourceFetcher($feedIoMock, new NullLogger());
    $articles = $fetcher->fetch(buildSource());

    expect($articles)->toHaveCount(1)
        ->and($articles[0]->title)->toBe('Has Link');
});

test('fetch rejette une source avec schéma file:// (protection SSRF)', function (): void {
    $feedIo = $this->createMock(FeedIo::class);
    $fetcher = new FeedIoSourceFetcher($feedIo, new NullLogger());

    expect(static fn () => $fetcher->fetch(buildSource('file:///etc/passwd')))
        ->toThrow(RuntimeException::class, 'Schéma URL non autorisé');
});
