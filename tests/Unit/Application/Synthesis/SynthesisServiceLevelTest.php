<?php

declare(strict_types=1);

use App\Application\Synthesis\SynthesisService;
use App\Domain\Synthesis\ArticleContentFetcherInterface;
use App\Domain\Synthesis\FetchedContent;
use App\Domain\Synthesis\MistralClientInterface;
use App\Domain\Synthesis\SynthesisCacheInterface;
use App\Domain\Synthesis\SynthesisLevel;
use App\Domain\Synthesis\SynthesisRequest;
use App\Domain\Synthesis\SynthesisResponse;
use App\Domain\Synthesis\SynthesisResult;
use App\Domain\Synthesis\SynthesisResultRepositoryInterface;
use Psr\Log\NullLogger;

/*
 * Unit tests — SynthesisService : gestion des niveaux (US-011 / T-011-11)
 *
 * Couvre :
 * - Prompt adapté au niveau (CONCISE / DETAILED / NARRATIVE)
 * - Timeout adapté au niveau (15 / 30 / 45 s)
 * - 3 synthèses pour la même URL avec niveaux différents → 3 clés cache distinctes
 * - Cache hit sur clé exacte (même niveau = hit, niveau différent = miss)
 * - Niveau persisté dans SynthesisResult
 * - PII-free : aucun UUID/e-mail dans le prompt envoyé
 */

// ── Stubs ─────────────────────────────────────────────────────────────────────

/**
 * Stub MistralClientInterface capturant le prompt, le contenu et le timeout.
 */
function levelMistralStub(): MistralClientInterface
{
    return new class implements MistralClientInterface {
        /** @var array<array{prompt: string, content: string, timeout: int}> */
        public array $calls = [];

        public function complete(string $systemPrompt, string $userContent, int $timeoutSeconds = 15): string
        {
            $this->calls[] = [
                'prompt' => $systemPrompt,
                'content' => $userContent,
                'timeout' => $timeoutSeconds,
            ];

            return <<<'MISTRAL'
                BRIEFLY AI: This is a test summary generated for the level test.

                KEY POINTS:
                01 First key point for level test
                02 Second key point for level test
                03 Third key point for level test

                SOURCES:
                Test Source
                MISTRAL;
        }
    };
}

/**
 * Stub ArticleContentFetcherInterface retournant un contenu fixe.
 */
function levelContentFetcherStub(): ArticleContentFetcherInterface
{
    return new class implements ArticleContentFetcherInterface {
        public function fetchContent(string $url): FetchedContent
        {
            return new FetchedContent(
                text: 'Article content for level testing. Enough content to generate a summary.',
                isPartial: false,
            );
        }
    };
}

/**
 * Stub SynthesisResultRepositoryInterface capturant les résultats.
 */
function levelRepositoryStub(): SynthesisResultRepositoryInterface
{
    return new class implements SynthesisResultRepositoryInterface {
        /** @var SynthesisResult[] */
        public array $saved = [];

        public function save(SynthesisResult $result): void
        {
            $this->saved[] = $result;
        }
    };
}

/**
 * Stub SynthesisCacheInterface capturant les get/set.
 */
function levelCacheStub(): SynthesisCacheInterface
{
    return new class implements SynthesisCacheInterface {
        /** @var array<string, SynthesisResponse> */
        public array $storage = [];
        /** @var string[] */
        public array $setKeys = [];
        /** @var string[] */
        public array $getKeys = [];

        public function get(string $cacheKey): ?SynthesisResponse
        {
            $this->getKeys[] = $cacheKey;

            return $this->storage[$cacheKey] ?? null;
        }

        public function set(string $cacheKey, SynthesisResponse $response, int $ttl): void
        {
            $this->setKeys[] = $cacheKey;
            $this->storage[$cacheKey] = $response;
        }
    };
}

/**
 * Construit un SynthesisService avec tous les stubs.
 */
function makeLevelService(
    MistralClientInterface $mistral,
    ArticleContentFetcherInterface $fetcher,
    SynthesisResultRepositoryInterface $repo,
    ?SynthesisCacheInterface $cache = null,
): SynthesisService {
    return new SynthesisService(
        mistralClient: $mistral,
        contentFetcher: $fetcher,
        repository: $repo,
        logger: new NullLogger(),
        cache: $cache,
    );
}

// ── Timeout adapté au niveau ──────────────────────────────────────────────────

test('timeout CONCISE est 15 secondes', function (): void {
    $mistral = levelMistralStub();
    $service = makeLevelService($mistral, levelContentFetcherStub(), levelRepositoryStub());

    $service->synthesize(new SynthesisRequest('https://example.com/article', SynthesisLevel::CONCISE));

    expect($mistral->calls[0]['timeout'])->toBe(15);
});

test('timeout DETAILED est 30 secondes', function (): void {
    $mistral = levelMistralStub();
    $service = makeLevelService($mistral, levelContentFetcherStub(), levelRepositoryStub());

    $service->synthesize(new SynthesisRequest('https://example.com/article', SynthesisLevel::DETAILED));

    expect($mistral->calls[0]['timeout'])->toBe(30);
});

test('timeout NARRATIVE est 45 secondes', function (): void {
    $mistral = levelMistralStub();
    $service = makeLevelService($mistral, levelContentFetcherStub(), levelRepositoryStub());

    $service->synthesize(new SynthesisRequest('https://example.com/article', SynthesisLevel::NARRATIVE));

    expect($mistral->calls[0]['timeout'])->toBe(45);
});

// ── Prompt adapté au niveau ───────────────────────────────────────────────────

test('le prompt envoyé pour CONCISE est celui de SynthesisLevel::CONCISE', function (): void {
    $mistral = levelMistralStub();
    $service = makeLevelService($mistral, levelContentFetcherStub(), levelRepositoryStub());

    $service->synthesize(new SynthesisRequest('https://example.com/article', SynthesisLevel::CONCISE));

    expect($mistral->calls[0]['prompt'])->toBe(SynthesisLevel::CONCISE->promptInstructions());
});

test('le prompt envoyé pour DETAILED est celui de SynthesisLevel::DETAILED', function (): void {
    $mistral = levelMistralStub();
    $service = makeLevelService($mistral, levelContentFetcherStub(), levelRepositoryStub());

    $service->synthesize(new SynthesisRequest('https://example.com/article', SynthesisLevel::DETAILED));

    expect($mistral->calls[0]['prompt'])->toBe(SynthesisLevel::DETAILED->promptInstructions());
});

test('le prompt CONCISE ≠ prompt DETAILED ≠ prompt NARRATIVE (prompts distincts)', function (): void {
    $mistral = levelMistralStub();
    $service = makeLevelService($mistral, levelContentFetcherStub(), levelRepositoryStub());
    $url = 'https://example.com/article';

    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));
    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::DETAILED));
    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::NARRATIVE));

    $prompts = array_column($mistral->calls, 'prompt');
    expect(count(array_unique($prompts)))->toBe(3);
});

// ── Niveau persisté dans SynthesisResult ─────────────────────────────────────

test('SynthesisResult persisté avec level = "concise"', function (): void {
    $repo = levelRepositoryStub();
    $service = makeLevelService(levelMistralStub(), levelContentFetcherStub(), $repo);

    $service->synthesize(new SynthesisRequest('https://example.com/article', SynthesisLevel::CONCISE));

    expect($repo->saved[0]->getLevel())->toBe('concise');
});

test('SynthesisResult persisté avec level = "detailed"', function (): void {
    $repo = levelRepositoryStub();
    $service = makeLevelService(levelMistralStub(), levelContentFetcherStub(), $repo);

    $service->synthesize(new SynthesisRequest('https://example.com/article', SynthesisLevel::DETAILED));

    expect($repo->saved[0]->getLevel())->toBe('detailed');
});

test('SynthesisResult persisté avec level = "narrative"', function (): void {
    $repo = levelRepositoryStub();
    $service = makeLevelService(levelMistralStub(), levelContentFetcherStub(), $repo);

    $service->synthesize(new SynthesisRequest('https://example.com/article', SynthesisLevel::NARRATIVE));

    expect($repo->saved[0]->getLevel())->toBe('narrative');
});

// ── Cache : 3 clés distinctes par URL (US-011 T-011-11) ──────────────────────

test('3 niveaux pour la même URL génèrent 3 clés cache distinctes', function (): void {
    $cache = levelCacheStub();
    $service = makeLevelService(levelMistralStub(), levelContentFetcherStub(), levelRepositoryStub(), $cache);
    $url = 'https://example.com/article';

    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));
    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::DETAILED));
    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::NARRATIVE));

    expect(count(array_unique($cache->setKeys)))->toBe(3);
});

test('la clé cache CONCISE est sha256(url . "_concise")', function (): void {
    $cache = levelCacheStub();
    $service = makeLevelService(levelMistralStub(), levelContentFetcherStub(), levelRepositoryStub(), $cache);
    $url = 'https://example.com/article';

    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));

    $expectedKey = hash('sha256', $url . '_concise');
    expect($cache->setKeys[0])->toBe($expectedKey);
});

test('la clé cache DETAILED est sha256(url . "_detailed")', function (): void {
    $cache = levelCacheStub();
    $service = makeLevelService(levelMistralStub(), levelContentFetcherStub(), levelRepositoryStub(), $cache);
    $url = 'https://example.com/article';

    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::DETAILED));

    $expectedKey = hash('sha256', $url . '_detailed');
    expect($cache->setKeys[0])->toBe($expectedKey);
});

test('la clé cache NARRATIVE est sha256(url . "_narrative")', function (): void {
    $cache = levelCacheStub();
    $service = makeLevelService(levelMistralStub(), levelContentFetcherStub(), levelRepositoryStub(), $cache);
    $url = 'https://example.com/article';

    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::NARRATIVE));

    $expectedKey = hash('sha256', $url . '_narrative');
    expect($cache->setKeys[0])->toBe($expectedKey);
});

// ── Cache hit / miss par niveau ───────────────────────────────────────────────

test('cache hit sur même URL + même niveau → pas d\'appel Mistral', function (): void {
    $cache = levelCacheStub();
    $mistral = levelMistralStub();
    $service = makeLevelService($mistral, levelContentFetcherStub(), levelRepositoryStub(), $cache);
    $url = 'https://example.com/article';

    // Premier appel → cache miss → appel Mistral + mise en cache
    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));
    // Deuxième appel → cache hit → PAS d'appel Mistral
    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));

    expect($mistral->calls)->toHaveCount(1); // un seul appel Mistral
});

test('cache miss sur même URL + niveau différent → appel Mistral distinct', function (): void {
    $cache = levelCacheStub();
    $mistral = levelMistralStub();
    $service = makeLevelService($mistral, levelContentFetcherStub(), levelRepositoryStub(), $cache);
    $url = 'https://example.com/article';

    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));
    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::DETAILED));

    expect($mistral->calls)->toHaveCount(2); // deux appels Mistral distincts
});

// ── Service sans cache (backward compat) ──────────────────────────────────────

test('le service fonctionne sans injection de cache (rétrocompatibilité)', function (): void {
    $service = makeLevelService(levelMistralStub(), levelContentFetcherStub(), levelRepositoryStub());

    $result = $service->synthesize(new SynthesisRequest('https://example.com/article'));

    expect($result->response->content)->toStartWith('BRIEFLY AI:');
});

// ── PII-free ──────────────────────────────────────────────────────────────────

test('le prompt envoyé pour chaque niveau ne contient aucun UUID (PII-free)', function (): void {
    $uuidPattern = '/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i';
    $mistral = levelMistralStub();
    $service = makeLevelService($mistral, levelContentFetcherStub(), levelRepositoryStub());

    foreach (SynthesisLevel::cases() as $level) {
        $service->synthesize(new SynthesisRequest('https://example.com/article', $level));
    }

    foreach ($mistral->calls as $call) {
        expect($call['prompt'])->not->toMatch($uuidPattern);
        expect($call['content'])->not->toMatch($uuidPattern);
    }
});

// ── Niveau par défaut (US-011 scénario alternatif 2) ─────────────────────────

test('SynthesisRequest sans niveau explicite utilise CONCISE par défaut', function (): void {
    $repo = levelRepositoryStub();
    $service = makeLevelService(levelMistralStub(), levelContentFetcherStub(), $repo);

    $service->synthesize(new SynthesisRequest('https://example.com/article'));

    expect($repo->saved[0]->getLevel())->toBe('concise');
});
