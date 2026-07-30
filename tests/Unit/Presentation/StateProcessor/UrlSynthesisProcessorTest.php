<?php

declare(strict_types=1);

use ApiPlatform\Metadata\Post;
use App\Application\Quota\QuotaService;
use App\Application\Quota\UserUuidResolverInterface;
use App\Domain\Quota\QuotaCounterInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;
use App\Domain\Synthesis\InvalidSynthesisUrlException;
use App\Domain\Synthesis\SynthesisRequest;
use App\Domain\Synthesis\SynthesisResponse;
use App\Domain\Synthesis\SynthesisResponseWithCacheStatus;
use App\Domain\Synthesis\SynthesisServiceInterface;
use App\Domain\Synthesis\SynthesisUnavailableException;
use App\Presentation\ApiResource\SynthesisResource;
use App\Presentation\StateProcessor\UrlSynthesisProcessor;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/*
 * Unit tests — UrlSynthesisProcessor (Presentation layer)
 *
 * Couvre T-010-12 (partie unit) :
 *   - Nominal : POST /api/v1/synthesis avec URL valide → SynthesisResource avec "BRIEFLY AI:"
 *   - URL vide → UnprocessableEntityHttpException (422)
 *   - URL invalide (SSRF) → UnprocessableEntityHttpException (422)
 *   - Mistral KO → ServiceUnavailableHttpException (503) sans stacktrace
 *   - Quota épuisé → TooManyRequestsHttpException (429)
 *   - Redis KO → ServiceUnavailableHttpException (503)
 *   - Non authentifié → AccessDeniedException
 */

// ── Stubs ──────────────────────────────────────────────────────────────────────

function urlQuotaCounterStub(int $count = 0, bool $throwOnGet = false): QuotaCounterInterface
{
    return new class($count, $throwOnGet) implements QuotaCounterInterface {
        private int $current;

        public function __construct(int $count, private readonly bool $throw)
        {
            $this->current = $count;
        }

        public function getCount(string $userUuid, string $dateUtc): int
        {
            if ($this->throw) {
                throw new QuotaServiceUnavailableException('mock Redis KO');
            }

            return $this->current;
        }

        public function incrementAndExpire(string $userUuid, string $dateUtc, int $expireAt): int
        {
            return ++$this->current;
        }
    };
}

function urlUuidResolverStub(?string $uuid): UserUuidResolverInterface
{
    return new class($uuid) implements UserUuidResolverInterface {
        public function __construct(private readonly ?string $uuid)
        {
        }

        public function getCurrentUserUuid(): ?string
        {
            return $this->uuid;
        }
    };
}

function synthesisServiceStub(
    bool $throwInvalid = false,
    bool $throwUnavailable = false,
    string $cacheStatus = SynthesisResponseWithCacheStatus::MISS,
): SynthesisServiceInterface {
    return new class($throwInvalid, $throwUnavailable, $cacheStatus) implements SynthesisServiceInterface {
        public function __construct(
            private readonly bool $throwInvalid,
            private readonly bool $throwUnavailable,
            private readonly string $cacheStatus,
        ) {
        }

        public function synthesize(SynthesisRequest $request): SynthesisResponseWithCacheStatus
        {
            if ($this->throwInvalid) {
                throw new InvalidSynthesisUrlException('URL invalide');
            }

            if ($this->throwUnavailable) {
                throw new SynthesisUnavailableException('mock Mistral KO');
            }

            $response = new SynthesisResponse(
                content: 'BRIEFLY AI: Test synthesis content for the article.',
                keyPoints: ['01 First point', '02 Second point', '03 Third point'],
                sources: ['Test Source'],
                originalUrl: $request->url,
                isPartial: false,
            );

            return new SynthesisResponseWithCacheStatus($response, $this->cacheStatus);
        }
    };
}

function makeUrlSynthesisProcessor(
    SynthesisServiceInterface $synthesisService,
    int $quotaCount = 0,
    bool $redisKo = false,
    ?string $userUuid = 'test-user-uuid',
): UrlSynthesisProcessor {
    return new UrlSynthesisProcessor(
        synthesisService: $synthesisService,
        quotaService: new QuotaService(urlQuotaCounterStub($quotaCount, $redisKo)),
        userUuidResolver: urlUuidResolverStub($userUuid),
        logger: new NullLogger(),
    );
}

function urlSynthesisOperation(): Post
{
    return new Post(uriTemplate: '/v1/synthesis');
}

function inputResourceWithUrl(string $url): SynthesisResource
{
    return new SynthesisResource(url: $url);
}

// ── Scénario nominal ──────────────────────────────────────────────────────────

test('process retourne SynthesisResource avec préfixe "BRIEFLY AI:" pour URL valide', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub());

    $result = $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
    );

    expect($result)->toBeInstanceOf(SynthesisResource::class);
    expect($result->content)->toContain('BRIEFLY AI:');
});

test('process retourne keyPoints avec 3 éléments', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub());

    $result = $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
    );

    expect($result->keyPoints)->toHaveCount(3);
});

test('process retourne originalUrl égal à l\'URL soumise', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub());
    $url = 'https://example.com/article';

    $result = $processor->process(
        inputResourceWithUrl($url),
        urlSynthesisOperation(),
    );

    expect($result->originalUrl)->toBe($url);
});

test('process retourne isPartial=false pour article entièrement accessible', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub());

    $result = $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
    );

    expect($result->isPartial)->toBeFalse();
});

test('process retourne generatedAt non vide au format ISO 8601', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub());

    $result = $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
    );

    expect($result->generatedAt)->not->toBeEmpty();
    // Vérification format ISO 8601 basique
    expect($result->generatedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

// ── Scénario erreur 1 — URL invalide → 422 ───────────────────────────────────

test('process lève UnprocessableEntityHttpException si URL vide', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub());

    expect(static fn () => $processor->process(inputResourceWithUrl(''), urlSynthesisOperation()))
        ->toThrow(UnprocessableEntityHttpException::class);
});

test('process lève UnprocessableEntityHttpException si URL invalide (SSRF)', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub(throwInvalid: true));

    expect(static fn () => $processor->process(
        inputResourceWithUrl('http://192.168.1.1/admin'),
        urlSynthesisOperation(),
    ))->toThrow(UnprocessableEntityHttpException::class);
});

test('UnprocessableEntityHttpException contient le message URL invalide', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub());

    try {
        $processor->process(inputResourceWithUrl(''), urlSynthesisOperation());
        expect(true)->toBeFalse('Exception attendue non levée');
    } catch (UnprocessableEntityHttpException $e) {
        expect($e->getMessage())->toContain('URL invalide');
    }
});

// ── Scénario erreur 2 — Mistral KO → 503 ─────────────────────────────────────

test('process lève ServiceUnavailableHttpException si Mistral timeout', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub(throwUnavailable: true));

    expect(static fn () => $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
    ))->toThrow(ServiceUnavailableHttpException::class);
});

test('ServiceUnavailableHttpException ne contient pas de stacktrace (OWASP A05)', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub(throwUnavailable: true));

    try {
        $processor->process(
            inputResourceWithUrl('https://example.com/article'),
            urlSynthesisOperation(),
        );
        expect(true)->toBeFalse('Exception attendue non levée');
    } catch (ServiceUnavailableHttpException $e) {
        // Le message ne doit pas exposer de détails techniques
        expect($e->getMessage())->not->toContain('SynthesisUnavailableException');
        expect($e->getMessage())->not->toContain('MistralApiClient');
        expect($e->getMessage())->toContain('indisponible');
    }
});

// ── Quota ────────────────────────────────────────────────────────────────────

test('process lève TooManyRequestsHttpException si quota épuisé (count=3)', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub(), quotaCount: 3);

    expect(static fn () => $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
    ))->toThrow(TooManyRequestsHttpException::class);
});

test('TooManyRequestsHttpException a le header X-Quota-Remaining: 0', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub(), quotaCount: 3);

    try {
        $processor->process(
            inputResourceWithUrl('https://example.com/article'),
            urlSynthesisOperation(),
        );
        expect(true)->toBeFalse('Exception attendue non levée');
    } catch (TooManyRequestsHttpException $e) {
        expect($e->getHeaders())->toHaveKey('X-Quota-Remaining');
        expect($e->getHeaders()['X-Quota-Remaining'])->toBe('0');
    }
});

test('process lève ServiceUnavailableHttpException si Redis KO', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub(), redisKo: true);

    expect(static fn () => $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
    ))->toThrow(ServiceUnavailableHttpException::class);
});

// ── Authentification ──────────────────────────────────────────────────────────

test('process lève AccessDeniedException si utilisateur non authentifié', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub(), userUuid: null);

    expect(static fn () => $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
    ))->toThrow(AccessDeniedException::class);
});

// ── Header X-Cache (US-012) ───────────────────────────────────────────────────

test('process stocke X-Cache=MISS dans les attributs de la requête si cache miss', function (): void {
    $processor = makeUrlSynthesisProcessor(
        synthesisServiceStub(cacheStatus: SynthesisResponseWithCacheStatus::MISS),
    );

    $request = Symfony\Component\HttpFoundation\Request::create('/api/v1/synthesis', 'POST');

    $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
        context: ['request' => $request],
    );

    expect($request->attributes->get('synthesis_x_cache'))->toBe('MISS');
});

test('process stocke X-Cache=HIT dans les attributs de la requête si cache hit', function (): void {
    $processor = makeUrlSynthesisProcessor(
        synthesisServiceStub(cacheStatus: SynthesisResponseWithCacheStatus::HIT),
    );

    $request = Symfony\Component\HttpFoundation\Request::create('/api/v1/synthesis', 'POST');

    $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
        context: ['request' => $request],
    );

    expect($request->attributes->get('synthesis_x_cache'))->toBe('HIT');
});

test('process stocke X-Cache=BYPASS dans les attributs de la requête si Redis indisponible', function (): void {
    $processor = makeUrlSynthesisProcessor(
        synthesisServiceStub(cacheStatus: SynthesisResponseWithCacheStatus::BYPASS),
    );

    $request = Symfony\Component\HttpFoundation\Request::create('/api/v1/synthesis', 'POST');

    $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
        context: ['request' => $request],
    );

    expect($request->attributes->get('synthesis_x_cache'))->toBe('BYPASS');
});

test('process fonctionne sans request dans le context (pas d\'attribut X-Cache)', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub());

    // Sans context['request'], aucune exception ne doit être levée
    $result = $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
    );

    expect($result)->toBeInstanceOf(SynthesisResource::class);
});
