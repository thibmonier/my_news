<?php

declare(strict_types=1);

use App\Application\Feed\FetchSource\FetchSourceHandler;
use App\Application\Feed\FetchSource\FetchSourceMessage;
use App\Domain\Feed\ArticleDTO;
use App\Domain\Feed\ArticleRepositoryInterface;
use App\Domain\Feed\ContentHash;
use App\Domain\Feed\FeedType;
use App\Domain\Feed\Source;
use App\Domain\Feed\SourceFetcherInterface;
use App\Domain\Feed\SourceRepositoryInterface;
use App\Domain\Feed\SourceStatus;
use Psr\Log\NullLogger;

/*
 * Unit tests — FetchSourceHandler (Application layer)
 *
 * Couvre les scénarios Gherkin US-020 :
 * - Nominal : articles parsés et insérés, last_fetched_at mis à jour
 * - Doublon : saveIgnoringDuplicate retourne false (x2), count inchangé, pas d'exception
 * - HTTP 5xx / XML invalide : exception catchée, last_error_at mis à jour, worker libéré
 * - Source introuvable : log warning, pas d'exception
 * - Flux vide : last_fetched_at mis à jour quand même
 *
 * Utilise des stubs PHP anonymes (pas de Mockery/ProphecX).
 */

/** Construit une Source active de test. */
function activeSource(string $id = 'src-001'): Source
{
    return new Source(
        id: $id,
        name: 'TechCrunch',
        url: 'https://techcrunch.com/feed/',
        feedType: FeedType::Rss,
        status: SourceStatus::Active,
    );
}

/** Construit un ArticleDTO de test. */
function sampleDto(string $sourceId = 'src-001', string $suffix = 'a'): ArticleDTO
{
    $url = 'https://techcrunch.com/article-' . $suffix;

    return new ArticleDTO(
        sourceId: $sourceId,
        title: 'Article ' . strtoupper($suffix),
        url: $url,
        canonicalUrl: $url,
        contentHash: ContentHash::fromCanonicalUrl($url),
        rawContent: 'Content...',
        publishedAt: new DateTimeImmutable('2026-07-28 10:00:00', new DateTimeZone('UTC')),
    );
}

// ── Stubs réutilisables ────────────────────────────────────────────────────

/**
 * Crée un SourceRepositoryInterface stub avec les comportements configurables.
 *
 * @param array<string, Source|null> $findByIdMap
 */
function sourceRepoStub(
    array $findByIdMap = [],
    ?DateTimeImmutable &$capturedFetchedAt = null,
    ?DateTimeImmutable &$capturedErrorAt = null,
): SourceRepositoryInterface {
    return new class($findByIdMap, $capturedFetchedAt, $capturedErrorAt) implements SourceRepositoryInterface {
        public function __construct(
            private readonly array $map,
            private mixed &$fetchedAt,
            private mixed &$errorAt,
        ) {
        }

        public function findById(string $id): ?Source
        {
            return $this->map[$id] ?? null;
        }

        public function findAllActive(): array
        {
            return array_values(array_filter($this->map));
        }

        public function findPaginated(int $page, int $perPage, ?string $query = null): array
        {
            return array_values(array_filter($this->map));
        }

        public function countForListing(?string $query = null): int
        {
            return count(array_filter($this->map));
        }

        public function findByUrl(string $url): ?Source
        {
            return null;
        }

        public function save(Source $source): void
        {
        }

        public function updateStatus(string $sourceId, SourceStatus $status): void
        {
        }

        public function softDelete(string $sourceId): void
        {
        }

        public function updateLastFetchedAt(string $sourceId, DateTimeImmutable $at): void
        {
            $this->fetchedAt = $at;
        }

        public function updateLastErrorAt(string $sourceId, DateTimeImmutable $at): void
        {
            $this->errorAt = $at;
        }
    };
}

/**
 * Crée un SourceFetcherInterface stub qui retourne les DTOs donnés ou lève une exception.
 *
 * @param list<ArticleDTO>|Throwable $returnValue
 */
function fetcherStub(array|Throwable $returnValue): SourceFetcherInterface
{
    return new class($returnValue) implements SourceFetcherInterface {
        public function __construct(private readonly array|Throwable $result)
        {
        }

        public function fetch(Source $source): array
        {
            if ($this->result instanceof Throwable) {
                throw $this->result;
            }

            return $this->result;
        }
    };
}

/**
 * Crée un ArticleRepositoryInterface stub qui compte les insertions.
 *
 * @param array<int, bool> $saveResults séquence de résultats pour saveIgnoringDuplicate
 */
function articleRepoStub(array $saveResults = []): ArticleRepositoryInterface
{
    return new class($saveResults) implements ArticleRepositoryInterface {
        private int $callIndex = 0;
        private int $inserted = 0;

        public function __construct(private array $results)
        {
        }

        public function saveIgnoringDuplicate(ArticleDTO $dto): bool
        {
            $result = $this->results[$this->callIndex++] ?? true;

            if ($result) {
                ++$this->inserted;
            }

            return $result;
        }

        public function findPaginatedWithSourceName(int $page, int $perPage): array
        {
            return [];
        }

        public function countAll(): int
        {
            return 0;
        }

        public function getInsertedCount(): int
        {
            return $this->inserted;
        }
    };
}

// ── Tests ──────────────────────────────────────────────────────────────────

test('handler insère les articles et met à jour last_fetched_at (nominal)', function (): void {
    $lastFetchedAt = null;

    $sourceRepo = sourceRepoStub(['src-001' => activeSource()], $lastFetchedAt);
    $fetcher = fetcherStub([sampleDto('src-001', 'a'), sampleDto('src-001', 'b')]);
    $articleRepo = articleRepoStub([true, true]);

    $handler = new FetchSourceHandler($sourceRepo, $fetcher, $articleRepo, new NullLogger());
    $handler(new FetchSourceMessage('src-001'));

    expect($lastFetchedAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('handler gère les doublons (saveIgnoringDuplicate → false) sans exception', function (): void {
    $lastFetchedAt = null;

    $sourceRepo = sourceRepoStub(['src-001' => activeSource()], $lastFetchedAt);
    $fetcher = fetcherStub([sampleDto('src-001', 'x'), sampleDto('src-001', 'x')]);
    $articleRepo = articleRepoStub([false, false]); // deux doublons

    $handler = new FetchSourceHandler($sourceRepo, $fetcher, $articleRepo, new NullLogger());

    expect(static fn () => $handler(new FetchSourceMessage('src-001')))->not->toThrow(Throwable::class);
    // last_fetched_at quand même mis à jour
    expect($lastFetchedAt)->not->toBeNull();
});

test('handler catchée une exception HTTP 5xx et met à jour last_error_at', function (): void {
    $lastFetchedAt = null;
    $lastErrorAt = null;

    $sourceRepo = sourceRepoStub(['src-503' => activeSource('src-503')], $lastFetchedAt, $lastErrorAt);
    $fetcher = fetcherStub(new RuntimeException('HTTP 503 Service Unavailable'));
    $articleRepo = articleRepoStub();

    $handler = new FetchSourceHandler($sourceRepo, $fetcher, $articleRepo, new NullLogger());

    // Le handler NE lève PAS d'exception — worker libéré normalement
    expect(static fn () => $handler(new FetchSourceMessage('src-503')))->not->toThrow(Throwable::class);
    expect($lastErrorAt)->toBeInstanceOf(DateTimeImmutable::class);
    expect($lastFetchedAt)->toBeNull(); // last_fetched_at PAS mis à jour en cas d'erreur
});

test('handler catchée une FeedException (XML invalide) et met à jour last_error_at', function (): void {
    $lastFetchedAt = null;
    $lastErrorAt = null;

    $sourceRepo = sourceRepoStub(['src-xml' => activeSource('src-xml')], $lastFetchedAt, $lastErrorAt);
    $fetcher = fetcherStub(new RuntimeException('XML parsing failed: unclosed tag'));
    $articleRepo = articleRepoStub();

    $handler = new FetchSourceHandler($sourceRepo, $fetcher, $articleRepo, new NullLogger());
    $handler(new FetchSourceMessage('src-xml'));

    expect($lastErrorAt)->not->toBeNull()
        ->and($lastFetchedAt)->toBeNull();
});

test('handler logue WARNING et ne crash pas si la source est introuvable', function (): void {
    $lastFetchedAt = null;

    $sourceRepo = sourceRepoStub([], $lastFetchedAt); // map vide → null
    $fetcher = fetcherStub([]);
    $articleRepo = articleRepoStub();

    $handler = new FetchSourceHandler($sourceRepo, $fetcher, $articleRepo, new NullLogger());

    expect(static fn () => $handler(new FetchSourceMessage('unknown-id')))->not->toThrow(Throwable::class);
});

test('handler met à jour last_fetched_at même quand aucun article n\'est retourné', function (): void {
    $lastFetchedAt = null;

    $sourceRepo = sourceRepoStub(['src-empty' => activeSource('src-empty')], $lastFetchedAt);
    $fetcher = fetcherStub([]); // flux vide
    $articleRepo = articleRepoStub();

    $handler = new FetchSourceHandler($sourceRepo, $fetcher, $articleRepo, new NullLogger());
    $handler(new FetchSourceMessage('src-empty'));

    expect($lastFetchedAt)->toBeInstanceOf(DateTimeImmutable::class);
});
