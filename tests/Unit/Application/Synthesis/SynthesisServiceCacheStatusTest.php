<?php

declare(strict_types=1);

use App\Application\Synthesis\SynthesisService;
use App\Application\Synthesis\UrlNormalizer;
use App\Domain\Synthesis\ArticleContentFetcherInterface;
use App\Domain\Synthesis\FetchedContent;
use App\Domain\Synthesis\InvalidSynthesisUrlException;
use App\Domain\Synthesis\MistralClientInterface;
use App\Domain\Synthesis\SynthesisCacheInterface;
use App\Domain\Synthesis\SynthesisLevel;
use App\Domain\Synthesis\SynthesisRequest;
use App\Domain\Synthesis\SynthesisResponse;
use App\Domain\Synthesis\SynthesisResponseWithCacheStatus;
use App\Domain\Synthesis\SynthesisResult;
use App\Domain\Synthesis\SynthesisResultRepositoryInterface;
use Psr\Log\NullLogger;

/*
 * Unit tests — SynthesisService : statuts de cache HIT / MISS / BYPASS (US-012 T-012-05)
 *
 * Couvre les scénarios Gherkin US-012 :
 *   - Cache HIT  : 2e appel même URL+niveau → cacheStatus=HIT, 0 appel Mistral
 *   - Cache MISS : 1er appel → cacheStatus=MISS, appel Mistral + écriture Redis
 *   - BYPASS     : cache lève \RuntimeException → cacheStatus=BYPASS, Mistral appelé
 *   - Niveaux distincts : 2 entrées cache indépendantes concise vs detailed
 *   - Normalisation URL : URLs équivalentes → même clé cache (hit garanti)
 *   - Injection clé : URL avec \r\n → InvalidSynthesisUrlException (HTTP 422)
 */

// ── Stubs ──────────────────────────────────────────────────────────────────────

/**
 * Stub MistralClientInterface — compte les appels et retourne une réponse valide.
 */
function cacheStatusMistralStub(): MistralClientInterface
{
    return new class implements MistralClientInterface {
        public int $callCount = 0;

        public function complete(string $systemPrompt, string $userContent, int $timeoutSeconds = 15): string
        {
            ++$this->callCount;

            return <<<'MISTRAL'
                BRIEFLY AI: Cache status test summary.

                KEY POINTS:
                01 First key point
                02 Second key point
                03 Third key point

                SOURCES:
                Test Source
                MISTRAL;
        }
    };
}

/**
 * Stub ArticleContentFetcherInterface — retourne un contenu minimal.
 */
function cacheStatusFetcherStub(): ArticleContentFetcherInterface
{
    return new class implements ArticleContentFetcherInterface {
        public function fetchContent(string $url): FetchedContent
        {
            return new FetchedContent(
                text: 'Article content for cache status testing.',
                isPartial: false,
            );
        }
    };
}

/**
 * Stub SynthesisResultRepositoryInterface — silencieux.
 */
function cacheStatusRepoStub(): SynthesisResultRepositoryInterface
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
 * Stub SynthesisCacheInterface en mémoire — comportement normal (hit/miss).
 */
function inMemoryCacheStub(): SynthesisCacheInterface
{
    return new class implements SynthesisCacheInterface {
        /** @var array<string, SynthesisResponse> */
        public array $storage = [];
        /** @var string[] */
        public array $setKeys = [];

        public function get(string $cacheKey): ?SynthesisResponse
        {
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
 * Stub SynthesisCacheInterface qui lève une \RuntimeException sur get() — simule Redis KO.
 */
function unavailableCacheStub(): SynthesisCacheInterface
{
    return new class implements SynthesisCacheInterface {
        public function get(string $cacheKey): ?SynthesisResponse
        {
            throw new RuntimeException('Connexion Redis refusée — Cache indisponible (simulation)');
        }

        public function set(string $cacheKey, SynthesisResponse $response, int $ttl): void
        {
            // Ne devrait pas être appelé si BYPASS
        }
    };
}

/**
 * Construit un SynthesisService avec UrlNormalizer injecté.
 */
function makeCacheStatusService(
    MistralClientInterface $mistral,
    ArticleContentFetcherInterface $fetcher,
    SynthesisResultRepositoryInterface $repo,
    ?SynthesisCacheInterface $cache = null,
    bool $withNormalizer = true,
): SynthesisService {
    return new SynthesisService(
        mistralClient: $mistral,
        contentFetcher: $fetcher,
        repository: $repo,
        logger: new NullLogger(),
        cache: $cache,
        normalizer: $withNormalizer ? new UrlNormalizer() : null,
    );
}

// ── Scénario nominal — Cache HIT (US-012 scénario 1) ─────────────────────────

test('synthesize retourne cacheStatus=HIT au 2ème appel (même URL + niveau)', function (): void {
    $cache = inMemoryCacheStub();
    $service = makeCacheStatusService(cacheStatusMistralStub(), cacheStatusFetcherStub(), cacheStatusRepoStub(), $cache);
    $url = 'https://techcrunch.com/article-xyz';

    // 1er appel → MISS + mise en cache
    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));

    // 2ème appel → HIT
    $result = $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));

    expect($result->cacheStatus)->toBe(SynthesisResponseWithCacheStatus::HIT);
});

test('synthesize NE contacte PAS Mistral si cacheStatus=HIT (0 appel)', function (): void {
    $cache = inMemoryCacheStub();
    $mistral = cacheStatusMistralStub();
    $service = makeCacheStatusService($mistral, cacheStatusFetcherStub(), cacheStatusRepoStub(), $cache);
    $url = 'https://techcrunch.com/article-xyz';

    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE)); // MISS → 1 appel Mistral
    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE)); // HIT → 0 appel Mistral

    expect($mistral->callCount)->toBe(1); // un seul appel au total
});

test('synthesize retourne le même contenu au 2ème appel (hit = contenu identique)', function (): void {
    $cache = inMemoryCacheStub();
    $service = makeCacheStatusService(cacheStatusMistralStub(), cacheStatusFetcherStub(), cacheStatusRepoStub(), $cache);
    $url = 'https://techcrunch.com/article-xyz';

    $first = $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));
    $second = $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));

    expect($second->response->content)->toBe($first->response->content);
});

// ── Scénario alternatif 1 — Cache MISS (US-012 scénario 2) ───────────────────

test('synthesize retourne cacheStatus=MISS au 1er appel (cache vide)', function (): void {
    $cache = inMemoryCacheStub();
    $service = makeCacheStatusService(cacheStatusMistralStub(), cacheStatusFetcherStub(), cacheStatusRepoStub(), $cache);

    $result = $service->synthesize(new SynthesisRequest('https://wired.com/article-abc', SynthesisLevel::DETAILED));

    expect($result->cacheStatus)->toBe(SynthesisResponseWithCacheStatus::MISS);
});

test('synthesize écrit la synthèse dans le cache sur MISS', function (): void {
    $cache = inMemoryCacheStub();
    $service = makeCacheStatusService(cacheStatusMistralStub(), cacheStatusFetcherStub(), cacheStatusRepoStub(), $cache);

    $service->synthesize(new SynthesisRequest('https://wired.com/article-abc', SynthesisLevel::DETAILED));

    expect($cache->setKeys)->toHaveCount(1);
});

test('synthesize retourne cacheStatus=MISS quand aucun cache n\'est configuré', function (): void {
    $service = makeCacheStatusService(cacheStatusMistralStub(), cacheStatusFetcherStub(), cacheStatusRepoStub(), cache: null);

    $result = $service->synthesize(new SynthesisRequest('https://example.com/article'));

    expect($result->cacheStatus)->toBe(SynthesisResponseWithCacheStatus::MISS);
});

// ── Scénario alternatif 2 — Niveaux distincts → entrées cache indépendantes ───

test('niveaux concise et detailed sur la même URL génèrent 2 entrées cache distinctes', function (): void {
    $cache = inMemoryCacheStub();
    $service = makeCacheStatusService(cacheStatusMistralStub(), cacheStatusFetcherStub(), cacheStatusRepoStub(), $cache);
    $url = 'https://hbr.org/article-def';

    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));   // MISS concise
    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::DETAILED));  // MISS detailed

    expect($cache->setKeys)->toHaveCount(2);
    expect($cache->setKeys[0])->not->toBe($cache->setKeys[1]);
});

test('synthèse DETAILED après CONCISE est un MISS (clé différente)', function (): void {
    $cache = inMemoryCacheStub();
    $mistral = cacheStatusMistralStub();
    $service = makeCacheStatusService($mistral, cacheStatusFetcherStub(), cacheStatusRepoStub(), $cache);
    $url = 'https://hbr.org/article-def';

    $service->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));   // MISS → 1er appel
    $result = $service->synthesize(new SynthesisRequest($url, SynthesisLevel::DETAILED)); // MISS → 2ème appel

    expect($result->cacheStatus)->toBe(SynthesisResponseWithCacheStatus::MISS);
    expect($mistral->callCount)->toBe(2);
});

// ── Scénario erreur 1 — BYPASS si Redis indisponible (US-012 scénario 4) ──────

test('synthesize retourne cacheStatus=BYPASS si le cache lève une exception (Redis KO)', function (): void {
    $service = makeCacheStatusService(cacheStatusMistralStub(), cacheStatusFetcherStub(), cacheStatusRepoStub(), unavailableCacheStub());

    $result = $service->synthesize(new SynthesisRequest('https://example.com/article'));

    expect($result->cacheStatus)->toBe(SynthesisResponseWithCacheStatus::BYPASS);
});

test('synthesize appelle Mistral normalement si cacheStatus=BYPASS', function (): void {
    $mistral = cacheStatusMistralStub();
    $service = makeCacheStatusService($mistral, cacheStatusFetcherStub(), cacheStatusRepoStub(), unavailableCacheStub());

    $service->synthesize(new SynthesisRequest('https://example.com/article'));

    expect($mistral->callCount)->toBe(1); // Mistral appelé malgré Redis KO
});

test('synthesize retourne une réponse valide (BRIEFLY AI:) même en BYPASS', function (): void {
    $service = makeCacheStatusService(cacheStatusMistralStub(), cacheStatusFetcherStub(), cacheStatusRepoStub(), unavailableCacheStub());

    $result = $service->synthesize(new SynthesisRequest('https://example.com/article'));

    expect($result->response->content)->toStartWith('BRIEFLY AI:');
});

// ── Scénario erreur 2 — URL avec caractères de contrôle → 422 (US-012 scénario 5) ──

test('synthesize lève InvalidSynthesisUrlException si URL contient \\r\\n (injection de clé)', function (): void {
    $service = makeCacheStatusService(cacheStatusMistralStub(), cacheStatusFetcherStub(), cacheStatusRepoStub(), inMemoryCacheStub());

    expect(static fn () => $service->synthesize(
        new SynthesisRequest("https://example.com/article\r\nX-Inject: evil"),
    ))->toThrow(InvalidSynthesisUrlException::class);
});

test('synthesize NE contacte PAS Mistral si URL invalide avec \\r\\n', function (): void {
    $mistral = cacheStatusMistralStub();
    $service = makeCacheStatusService($mistral, cacheStatusFetcherStub(), cacheStatusRepoStub(), inMemoryCacheStub());

    try {
        $service->synthesize(new SynthesisRequest("https://example.com/article\r\ninjection"));
    } catch (InvalidSynthesisUrlException) {
        // attendu
    }

    expect($mistral->callCount)->toBe(0);
});

// ── Normalisation d'URL → même clé cache (canonicalisation US-012) ────────────

test('URLs équivalentes après normalisation → cacheStatus=HIT au 2ème appel', function (): void {
    $cache = inMemoryCacheStub();
    $service = makeCacheStatusService(cacheStatusMistralStub(), cacheStatusFetcherStub(), cacheStatusRepoStub(), $cache);

    // 1er appel avec URL mal formatée mais équivalente
    $service->synthesize(new SynthesisRequest(
        'HTTPS://TechCrunch.COM/article?z=1&a=2',
        SynthesisLevel::CONCISE,
    ));

    // 2ème appel avec URL canonique (devrait être HIT car même clé normalisée)
    $result = $service->synthesize(new SynthesisRequest(
        'https://techcrunch.com/article?a=2&z=1',
        SynthesisLevel::CONCISE,
    ));

    expect($result->cacheStatus)->toBe(SynthesisResponseWithCacheStatus::HIT);
});

test('URL avec majuscules et URL minuscules génèrent la même clé cache', function (): void {
    $cache = inMemoryCacheStub();
    $mistral = cacheStatusMistralStub();
    $service = makeCacheStatusService($mistral, cacheStatusFetcherStub(), cacheStatusRepoStub(), $cache);

    $service->synthesize(new SynthesisRequest('HTTPS://Example.COM/article', SynthesisLevel::CONCISE));
    $service->synthesize(new SynthesisRequest('https://example.com/article', SynthesisLevel::CONCISE));

    expect($mistral->callCount)->toBe(1); // 1 seul appel Mistral = hit au 2ème
});

test('url_hash loggué est calculé sur l\'URL normalisée (sha256 déterministe)', function (): void {
    $repo = cacheStatusRepoStub();
    $service = makeCacheStatusService(cacheStatusMistralStub(), cacheStatusFetcherStub(), $repo, inMemoryCacheStub());

    $service->synthesize(new SynthesisRequest('HTTPS://Example.COM/article?b=2&a=1', SynthesisLevel::CONCISE));

    // L'url_hash persisté doit correspondre à l'URL normalisée
    $normalizedUrl = 'https://example.com/article?a=1&b=2';
    expect($repo->saved[0]->getUrlHash())->toBe(hash('sha256', $normalizedUrl));
});
