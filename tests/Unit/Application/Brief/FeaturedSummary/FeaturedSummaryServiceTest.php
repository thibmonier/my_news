<?php

declare(strict_types=1);

use App\Application\Brief\FeaturedSummary\FeaturedSummaryService;
use App\Domain\Brief\BriefStoryPublicView;
use App\Domain\Brief\DailyBriefSummaryRepositoryInterface;
use App\Domain\Brief\FeaturedSummaryCacheInterface;
use App\Domain\Brief\FeaturedSummaryDTO;
use App\Domain\Synthesis\MistralClientInterface;
use App\Domain\Synthesis\SynthesisUnavailableException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/*
 * Unit tests — FeaturedSummaryService (US-006/T-006-09).
 *
 * Gherkin validé (US-006) :
 * - Scénario nominal       : Mistral appelé 1 fois, dto isFallback=false, cache set
 * - Cache hit              : Mistral jamais appelé (0 call réseau)
 * - Fallback Mistral KO    : dto isFallback=true, log WARNING featured_summary.fallback_used
 * - PII-free T-006-09      : aucun UUID user/session dans le prompt envoyé à Mistral
 *
 * Tous les ports sont mockés (stubs PHP anonymes — 0 appel réseau réel).
 * Nommage préfixé `fs006` pour éviter les collisions globales Pest.
 */

// ── Fixtures ──────────────────────────────────────────────────────────────────

function fs006MakeStory(int $pos = 1, string $title = 'AI révolutionne le dev', string $excerpt = 'Les LLM changent les pratiques.'): BriefStoryPublicView
{
    return new BriefStoryPublicView(
        position: $pos,
        articleTitle: $title,
        articleUrl: 'https://example.com/article-' . $pos,
        excerpt: $excerpt,
        sourceName: 'Tech Crunch',
        articleId: 'a1b2c3d4-e5f6-4789-abcd-ef0123456789',
        rawContent: $excerpt,
    );
}

/** @return list<BriefStoryPublicView> */
function fs006MakeStories(): array
{
    return [
        fs006MakeStory(1, 'AI révolutionne le développement logiciel', 'Les LLM changent les pratiques de développement.'),
        fs006MakeStory(2, 'Open Source : nouvelles licences en 2026', "Les licences open source évoluent face à l'IA."),
        fs006MakeStory(3, 'Sécurité APIs : nouvelles menaces OWASP 2026', 'Le rapport OWASP 2026 identifie de nouveaux vecteurs.'),
    ];
}

/**
 * Stub MistralClientInterface — capture le contenu utilisateur, retourne un texte fixe ou lève.
 */
function fs006MistralStub(bool $throw = false, string $response = 'Synthèse éditoriale des 3 histoires du jour.'): MistralClientInterface
{
    return new class($throw, $response) implements MistralClientInterface {
        /** @var list<array{system: string, user: string}> */
        public array $calls = [];

        public function __construct(
            private readonly bool $throw,
            private readonly string $text,
        ) {
        }

        public function complete(string $systemPrompt, string $userContent, int $timeoutSeconds = 30): string
        {
            $this->calls[] = ['system' => $systemPrompt, 'user' => $userContent];

            if ($this->throw) {
                throw new SynthesisUnavailableException('mock Mistral unavailable');
            }

            return $this->text;
        }
    };
}

/**
 * Stub FeaturedSummaryCacheInterface — hit optionnel + capture des sets.
 */
function fs006CacheStub(?FeaturedSummaryDTO $hit = null): FeaturedSummaryCacheInterface
{
    return new class($hit) implements FeaturedSummaryCacheInterface {
        /** @var list<array{key: string, dto: FeaturedSummaryDTO, ttl: int}> */
        public array $setCalls = [];

        public function __construct(private readonly ?FeaturedSummaryDTO $hit)
        {
        }

        public function get(string $dateKey): ?FeaturedSummaryDTO
        {
            return $this->hit;
        }

        public function set(string $dateKey, FeaturedSummaryDTO $summary, int $ttl): void
        {
            $this->setCalls[] = ['key' => $dateKey, 'dto' => $summary, 'ttl' => $ttl];
        }
    };
}

/**
 * Stub DailyBriefSummaryRepositoryInterface — capture les saves.
 */
function fs006RepoStub(): DailyBriefSummaryRepositoryInterface
{
    return new class implements DailyBriefSummaryRepositoryInterface {
        /** @var list<FeaturedSummaryDTO> */
        public array $saved = [];

        public function findByBriefId(string $briefId): ?FeaturedSummaryDTO
        {
            return null;
        }

        public function findLatest(): ?FeaturedSummaryDTO
        {
            return null;
        }

        public function save(FeaturedSummaryDTO $summary): void
        {
            $this->saved[] = $summary;
        }
    };
}

/**
 * Stub LoggerInterface qui capture les messages WARNING.
 */
function fs006CaptureLogger(): LoggerInterface
{
    return new class extends NullLogger {
        /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
        public array $logs = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
        }
    };
}

// ── Factory ───────────────────────────────────────────────────────────────────

function fs006MakeService(
    MistralClientInterface $mistral,
    DailyBriefSummaryRepositoryInterface $repo,
    FeaturedSummaryCacheInterface $cache,
    LoggerInterface $logger,
): FeaturedSummaryService {
    return new FeaturedSummaryService($mistral, $repo, $cache, $logger);
}

// ── Tests : generateForBrief ──────────────────────────────────────────────────

test('US-006 nominal : Mistral appelé 1 fois, dto isFallback=false retourné', function (): void {
    $mistral = fs006MistralStub(throw: false, response: 'Synthèse narrative du brief.');
    $repo = fs006RepoStub();
    $cache = fs006CacheStub(hit: null); // cache miss
    $service = fs006MakeService($mistral, $repo, $cache, new NullLogger());

    $dto = $service->generateForBrief(
        briefId: 'b1000000-0000-4000-a000-000000000001',
        date: new DateTimeImmutable('2026-07-30', new DateTimeZone('UTC')),
        stories: fs006MakeStories(),
    );

    expect($dto->isFallback)->toBeFalse();
    expect($dto->content)->toBe('Synthèse narrative du brief.');
    expect($mistral->calls)->toHaveCount(1);
});

test('US-006 nominal : cache set après appel Mistral (TTL 86400)', function (): void {
    $mistral = fs006MistralStub();
    $repo = fs006RepoStub();
    $cache = fs006CacheStub(hit: null);
    $service = fs006MakeService($mistral, $repo, $cache, new NullLogger());

    $service->generateForBrief(
        briefId: 'b1000000-0000-4000-a000-000000000001',
        date: new DateTimeImmutable('2026-07-30', new DateTimeZone('UTC')),
        stories: fs006MakeStories(),
    );

    expect($cache->setCalls)->toHaveCount(1);
    expect($cache->setCalls[0]['ttl'])->toBe(86400);
});

test('US-006 cache hit : Mistral jamais appelé, dto retourné depuis cache', function (): void {
    $cachedDto = new FeaturedSummaryDTO(
        briefId: 'b1000000-0000-4000-a000-000000000001',
        content: 'Contenu depuis cache Redis.',
        modelVersion: 'mistral-small-latest',
        generatedAt: new DateTimeImmutable('2026-07-30T05:00:00Z'),
        isFallback: false,
    );
    $mistral = fs006MistralStub();
    $repo = fs006RepoStub();
    $cache = fs006CacheStub(hit: $cachedDto);
    $service = fs006MakeService($mistral, $repo, $cache, new NullLogger());

    $dto = $service->generateForBrief(
        briefId: 'b1000000-0000-4000-a000-000000000001',
        date: new DateTimeImmutable('2026-07-30', new DateTimeZone('UTC')),
        stories: fs006MakeStories(),
    );

    expect($mistral->calls)->toHaveCount(0); // 0 appel LLM
    expect($dto->content)->toBe('Contenu depuis cache Redis.');
});

test('US-006 fallback : Mistral KO → dto isFallback=true avec texte générique', function (): void {
    $mistral = fs006MistralStub(throw: true);
    $repo = fs006RepoStub();
    $cache = fs006CacheStub(hit: null);
    $service = fs006MakeService($mistral, $repo, $cache, new NullLogger());

    $dto = $service->generateForBrief(
        briefId: 'b1000000-0000-4000-a000-000000000001',
        date: new DateTimeImmutable('2026-07-30', new DateTimeZone('UTC')),
        stories: fs006MakeStories(),
    );

    expect($dto->isFallback)->toBeTrue();
    expect($dto->content)->toContain('30/07/2026');
    expect($dto->modelVersion)->toBe('');
});

test('US-006 fallback : log WARNING featured_summary.fallback_used émis si Mistral KO', function (): void {
    $mistral = fs006MistralStub(throw: true);
    $repo = fs006RepoStub();
    $cache = fs006CacheStub(hit: null);
    $logger = fs006CaptureLogger();
    $service = fs006MakeService($mistral, $repo, $cache, $logger);

    $service->generateForBrief(
        briefId: 'b1000000-0000-4000-a000-000000000001',
        date: new DateTimeImmutable('2026-07-30', new DateTimeZone('UTC')),
        stories: fs006MakeStories(),
    );

    $warningLogs = array_filter($logger->logs, fn ($l) => 'featured_summary.fallback_used' === $l['message']);
    expect(array_values($warningLogs))->toHaveCount(1);
    expect($warningLogs[array_key_first($warningLogs)]['level'])->toBe('warning');
});

test('US-006 PII-free T-006-09 : le prompt Mistral ne contient aucun UUID utilisateur', function (): void {
    $mistral = fs006MistralStub();
    $repo = fs006RepoStub();
    $cache = fs006CacheStub(hit: null);
    $service = fs006MakeService($mistral, $repo, $cache, new NullLogger());

    $service->generateForBrief(
        briefId: 'b1000000-0000-4000-a000-000000000001',
        date: new DateTimeImmutable('2026-07-30', new DateTimeZone('UTC')),
        stories: fs006MakeStories(),
    );

    expect($mistral->calls)->toHaveCount(1);

    $userContent = $mistral->calls[0]['user'];

    // Le prompt ne doit PAS contenir d'UUID (format 8-4-4-4-12)
    // Note : briefId n'est PAS dans le prompt (buildUserContent n'utilise que title+excerpt+date)
    $uuidPattern = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';
    expect(preg_match($uuidPattern, $userContent))->toBe(0);
});

test('US-006 PII-free : le prompt contient uniquement titres, extraits et date (jamais articleId)', function (): void {
    $mistral = fs006MistralStub();
    $repo = fs006RepoStub();
    $cache = fs006CacheStub(hit: null);
    $service = fs006MakeService($mistral, $repo, $cache, new NullLogger());

    $service->generateForBrief(
        briefId: 'b1000000-0000-4000-a000-000000000001',
        date: new DateTimeImmutable('2026-07-30', new DateTimeZone('UTC')),
        stories: fs006MakeStories(),
    );

    $userContent = $mistral->calls[0]['user'];

    // Le prompt contient les titres et extraits publics
    expect($userContent)->toContain('AI révolutionne le développement logiciel');
    expect($userContent)->toContain('Open Source');
    expect($userContent)->toContain('30/07/2026');

    // articleId spécifique (UUID) absent
    expect($userContent)->not->toContain('a1b2c3d4-e5f6-4789-abcd-ef0123456789');
});

// ── Tests : getForToday ────────────────────────────────────────────────────────

test('US-006 getForToday : retourne dto depuis cache Redis si chaud', function (): void {
    $cachedDto = new FeaturedSummaryDTO(
        briefId: 'b1000000-0000-4000-a000-000000000001',
        content: 'Depuis cache getForToday.',
        modelVersion: 'mistral-small-latest',
        generatedAt: new DateTimeImmutable('2026-07-30T05:00:00Z'),
        isFallback: false,
    );
    $repo = fs006RepoStub();
    $cache = fs006CacheStub(hit: $cachedDto);
    $service = fs006MakeService(fs006MistralStub(), $repo, $cache, new NullLogger());

    $dto = $service->getForToday(new DateTimeImmutable('2026-07-30T10:00:00Z'));

    expect($dto?->content)->toBe('Depuis cache getForToday.');
});

test('US-006 getForToday : retourne null si cache froid et DB vide', function (): void {
    $cache = fs006CacheStub(hit: null); // cache miss
    $repo = fs006RepoStub(); // DB vide
    $service = fs006MakeService(fs006MistralStub(), $repo, $cache, new NullLogger());

    $dto = $service->getForToday(new DateTimeImmutable('2026-07-30T10:00:00Z'));

    expect($dto)->toBeNull();
});
