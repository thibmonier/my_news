<?php

declare(strict_types=1);

use ApiPlatform\Metadata\Post;
use App\Application\Quota\QuotaService;
use App\Application\Quota\UserUuidResolverInterface;
use App\Domain\Quota\QuotaCounterInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;
use App\Domain\Subscription\Subscription;
use App\Domain\Subscription\SubscriptionRepositoryInterface;
use App\Domain\Synthesis\InvalidSynthesisUrlException;
use App\Domain\Synthesis\SynthesisRequest;
use App\Domain\Synthesis\SynthesisResponse;
use App\Domain\Synthesis\SynthesisResponseWithCacheStatus;
use App\Domain\Synthesis\SynthesisServiceInterface;
use App\Domain\Synthesis\SynthesisUnavailableException;
use App\Presentation\ApiResource\SynthesisResource;
use App\Presentation\StateProcessor\UrlSynthesisProcessor;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/*
 * Unit tests — UrlSynthesisProcessor (Presentation layer)
 *
 * Couvre T-013-09 (partie unit) :
 *   - Nominal : POST /api/v1/synthesis avec URL valide → SynthesisResource avec "BRIEFLY AI:"
 *   - URL vide → UnprocessableEntityHttpException (422)
 *   - URL invalide (SSRF) → UnprocessableEntityHttpException (422)
 *   - Mistral KO → ServiceUnavailableHttpException (503) sans stacktrace
 *   - Quota épuisé (Free) → HttpException(402) avec X-Resets-At
 *   - Premium bypass → pas de 402 même quota épuisé côté Redis
 *   - Mistral KO post-débit (Free) → refund() appelé
 *   - Redis KO → ServiceUnavailableHttpException (503)
 *   - Non authentifié → AccessDeniedException
 *   - Header X-Date présent → WARNING loggué
 */

// ── Stubs ──────────────────────────────────────────────────────────────────────

function urlQuotaCounterStub(int $count = 0, bool $throwOnGet = false, bool &$decrementCalled = false): QuotaCounterInterface
{
    return new class($count, $throwOnGet, $decrementCalled) implements QuotaCounterInterface {
        private int $current;

        public function __construct(int $count, private readonly bool $throw, private mixed &$decrementCalled)
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

        public function decrement(string $userUuid, string $dateUtc): void
        {
            $this->decrementCalled = true;
            $this->current = max(0, $this->current - 1);
        }
    };
}

function urlSubRepoStub(bool $isPremium = false): SubscriptionRepositoryInterface
{
    return new class($isPremium) implements SubscriptionRepositoryInterface {
        public function __construct(private readonly bool $premium)
        {
        }

        public function isPremium(string $userUuid): bool
        {
            return $this->premium;
        }

        public function save(Subscription $subscription): void
        {
        }

        public function findByStripeEventId(string $eventId): ?Subscription
        {
            return null;
        }

        public function findByStripeSubscriptionId(string $subscriptionId): ?Subscription
        {
            return null;
        }

        public function findByStripeCustomerId(string $customerId): ?Subscription
        {
            return null;
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
    bool $isPremium = false,
    bool &$decrementCalled = false,
    mixed $logger = null,
): UrlSynthesisProcessor {
    return new UrlSynthesisProcessor(
        synthesisService: $synthesisService,
        quotaService: new QuotaService(urlQuotaCounterStub($quotaCount, $redisKo, $decrementCalled), urlSubRepoStub($isPremium)),
        userUuidResolver: urlUuidResolverStub($userUuid),
        logger: $logger ?? new NullLogger(),
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

test('process lève HttpException 402 si quota épuisé (count=3)', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub(), quotaCount: 3);

    expect(static fn () => $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
    ))->toThrow(HttpException::class);
});

test('HttpException 402 a le header X-Resets-At (signature quota_exceeded)', function (): void {
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub(), quotaCount: 3);

    try {
        $processor->process(
            inputResourceWithUrl('https://example.com/article'),
            urlSynthesisOperation(),
        );
        expect(true)->toBeFalse('Exception attendue non levée');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(Response::HTTP_PAYMENT_REQUIRED);
        expect($e->getHeaders())->toHaveKey('X-Resets-At');
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

// ── Premium bypass (T-013-02) ─────────────────────────────────────────────────

test('process ne lève PAS 402 pour un utilisateur Premium même avec count=3', function (): void {
    // count=3 = quota épuisé côté free, mais Premium bypass → succès
    $processor = makeUrlSynthesisProcessor(synthesisServiceStub(), quotaCount: 3, isPremium: true);

    $result = $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
    );

    expect($result)->toBeInstanceOf(SynthesisResource::class);
});

// ── Remboursement quota (T-013-06) ────────────────────────────────────────────

test('process appelle refund() si Mistral KO après débit quota Free', function (): void {
    $decrementCalled = false;
    $processor = makeUrlSynthesisProcessor(
        synthesisServiceStub(throwUnavailable: true),
        quotaCount: 0,
        decrementCalled: $decrementCalled,
    );

    try {
        $processor->process(
            inputResourceWithUrl('https://example.com/article'),
            urlSynthesisOperation(),
        );
    } catch (ServiceUnavailableHttpException) {
        // attendu
    }

    expect($decrementCalled)->toBeTrue();
});

test('process n\'appelle PAS refund() si Mistral KO pour un utilisateur Premium', function (): void {
    $decrementCalled = false;
    $processor = makeUrlSynthesisProcessor(
        synthesisServiceStub(throwUnavailable: true),
        quotaCount: 0,
        isPremium: true,
        decrementCalled: $decrementCalled,
    );

    try {
        $processor->process(
            inputResourceWithUrl('https://example.com/article'),
            urlSynthesisOperation(),
        );
    } catch (ServiceUnavailableHttpException) {
        // attendu
    }

    expect($decrementCalled)->toBeFalse();
});

// ── Sécurité : header X-Date → WARNING loggué ─────────────────────────────────

test('process log un WARNING si le header X-Date est présent (tentative manipulation)', function (): void {
    $loggedMessages = [];
    $spyLogger = new class($loggedMessages) extends AbstractLogger {
        public function __construct(private array &$messages)
        {
        }

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = ['level' => $level, 'message' => $message];
        }
    };

    $processor = makeUrlSynthesisProcessor(synthesisServiceStub(), logger: $spyLogger);

    $request = Symfony\Component\HttpFoundation\Request::create('/api/v1/synthesis', 'POST');
    $request->headers->set('X-Date', '2020-01-01T00:00:00Z');

    $processor->process(
        inputResourceWithUrl('https://example.com/article'),
        urlSynthesisOperation(),
        context: ['request' => $request],
    );

    $warnings = array_filter($loggedMessages, fn ($m) => 'warning' === $m['level']);
    expect($warnings)->not->toBeEmpty();
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
