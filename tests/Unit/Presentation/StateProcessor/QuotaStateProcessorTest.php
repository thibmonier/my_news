<?php

declare(strict_types=1);

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use App\Application\Quota\QuotaService;
use App\Application\Quota\UserUuidResolverInterface;
use App\Domain\Quota\QuotaCounterInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;
use App\Presentation\ApiResource\SynthesisResource;
use App\Presentation\StateProcessor\QuotaStateProcessor;
use App\Presentation\StateProcessor\SynthesisStubProcessor;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/*
 * Unit tests — QuotaStateProcessor (Presentation layer)
 *
 * Couvre les scénarios Gherkin US-033 (T-033-08) :
 *   - Quota OK (count < 3) : délégation au processor interne → SynthesisResource
 *   - Quota dépassé (count = 3) : TooManyRequestsHttpException (HTTP 429)
 *     + header X-Quota-Remaining: 0
 *   - Redis KO (QuotaServiceUnavailableException) : ServiceUnavailableHttpException (HTTP 503)
 *   - Utilisateur non authentifié (resolver retourne null) : AccessDeniedException
 *   - Réponse enrichie : quotaUsed + quotaRemaining après génération réussie
 *
 * Utilise des stubs PHP anonymes (pas de Mockery).
 * UserUuidResolverInterface permet de tester sans Symfony Security (deptrac + testabilité).
 */

// ── Stubs ──────────────────────────────────────────────────────────────────────

/**
 * Stub QuotaCounterInterface — paramétrable par count initial et simulation Redis KO.
 */
function processorCounterStub(
    int $count = 0,
    bool $throwOnGet = false,
    bool $throwOnIncr = false,
): QuotaCounterInterface {
    return new class($count, $throwOnGet, $throwOnIncr) implements QuotaCounterInterface {
        private int $currentCount;

        public function __construct(
            int $count,
            private readonly bool $throwGet,
            private readonly bool $throwIncr,
        ) {
            $this->currentCount = $count;
        }

        public function getCount(string $userUuid, string $dateUtc): int
        {
            if ($this->throwGet) {
                throw new QuotaServiceUnavailableException('mock Redis KO on GET');
            }

            return $this->currentCount;
        }

        public function incrementAndExpire(string $userUuid, string $dateUtc, int $expireAtTimestamp): int
        {
            if ($this->throwIncr) {
                throw new QuotaServiceUnavailableException('mock Redis KO on INCR');
            }

            return ++$this->currentCount;
        }
    };
}

/**
 * Stub UserUuidResolverInterface — retourne l'UUID donné ou null.
 */
function uuidResolverStub(?string $uuid): UserUuidResolverInterface
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

/**
 * Construit un QuotaStateProcessor complet avec les stubs fournis.
 */
function makeQuotaStateProcessor(
    QuotaCounterInterface $counter,
    ?string $userUuid,
): QuotaStateProcessor {
    return new QuotaStateProcessor(
        innerProcessor: new SynthesisStubProcessor(),
        quotaService: new QuotaService($counter),
        userUuidResolver: uuidResolverStub($userUuid),
        logger: new NullLogger(),
    );
}

function synthesisOperation(): Operation
{
    return new Post(uriTemplate: '/v1/articles/{id}/synthesize');
}

const PROCESSOR_UUID = 'deadbeef-1111-2222-3333-444455556666';
const ARTICLE_ID = 'article-uuid-001';

// ── Scénario nominal — quota OK ───────────────────────────────────────────────

test('processor retourne SynthesisResource quand quota OK (count=0)', function (): void {
    $processor = makeQuotaStateProcessor(processorCounterStub(count: 0), PROCESSOR_UUID);

    $result = $processor->process(null, synthesisOperation(), ['id' => ARTICLE_ID], []);

    expect($result)->toBeInstanceOf(SynthesisResource::class)
        ->and($result->content)->toContain('BRIEFLY AI:')
        ->and($result->articleId)->toBe(ARTICLE_ID);
});

test('processor retourne quota_used=2 et quota_remaining=1 après 2e consommation', function (): void {
    // count=1 avant l'appel → consumeOrDeny → count=2 → remaining=1
    $processor = makeQuotaStateProcessor(processorCounterStub(count: 1), PROCESSOR_UUID);

    $result = $processor->process(null, synthesisOperation(), ['id' => ARTICLE_ID], []);

    expect($result->quotaUsed)->toBe(2)
        ->and($result->quotaRemaining)->toBe(1);
});

test('processor retourne quota_used=3 et quota_remaining=0 après 3e consommation', function (): void {
    // count=2 avant l'appel → consumeOrDeny → count=3 → remaining=0
    $processor = makeQuotaStateProcessor(processorCounterStub(count: 2), PROCESSOR_UUID);

    $result = $processor->process(null, synthesisOperation(), ['id' => ARTICLE_ID], []);

    expect($result->quotaUsed)->toBe(3)
        ->and($result->quotaRemaining)->toBe(0);
});

test('processor retourne un id UUID non vide pour la synthèse', function (): void {
    $result = makeQuotaStateProcessor(processorCounterStub(), PROCESSOR_UUID)->process(
        null, synthesisOperation(), ['id' => ARTICLE_ID], [],
    );

    expect($result->id)->not->toBeEmpty()
        ->and(strlen($result->id))->toBe(36); // UUID RFC 4122 = 36 chars avec tirets
});

// ── Scénario erreur 1 — quota épuisé → HTTP 429 ───────────────────────────────

test('processor lève TooManyRequestsHttpException quand quota épuisé (count=3)', function (): void {
    $processor = makeQuotaStateProcessor(processorCounterStub(count: 3), PROCESSOR_UUID);

    expect(static fn () => $processor->process(null, synthesisOperation(), ['id' => ARTICLE_ID], []))
        ->toThrow(TooManyRequestsHttpException::class);
});

test('TooManyRequestsHttpException a le header X-Quota-Remaining: 0 (US-033 scénario erreur 1)', function (): void {
    $processor = makeQuotaStateProcessor(processorCounterStub(count: 3), PROCESSOR_UUID);

    try {
        $processor->process(null, synthesisOperation(), ['id' => ARTICLE_ID], []);
        expect(true)->toBeFalse('Exception attendue non levée');
    } catch (TooManyRequestsHttpException $e) {
        expect($e->getHeaders())->toHaveKey('X-Quota-Remaining')
            ->and($e->getHeaders()['X-Quota-Remaining'])->toBe('0')
            ->and($e->getMessage())->toContain('3 synthèses gratuites');
    }
});

test('processor NE génère PAS de synthèse si quota dépassé (clé Redis inchangée)', function (): void {
    $capturedIncr = false;

    $counter = new class($capturedIncr) implements QuotaCounterInterface {
        public function __construct(private mixed &$called)
        {
        }

        public function getCount(string $userUuid, string $dateUtc): int
        {
            return 3; // quota épuisé
        }

        public function incrementAndExpire(string $userUuid, string $dateUtc, int $expireAtTimestamp): int
        {
            $this->called = true; // ne doit jamais être appelé

            return 3;
        }
    };

    $processor = makeQuotaStateProcessor($counter, PROCESSOR_UUID);

    try {
        $processor->process(null, synthesisOperation(), ['id' => ARTICLE_ID], []);
    } catch (TooManyRequestsHttpException) {
        // attendu
    }

    expect($capturedIncr)->toBeFalse();
});

// ── Scénario erreur 2 — Redis KO → HTTP 503 ──────────────────────────────────

test('processor lève ServiceUnavailableHttpException si Redis KO (GET)', function (): void {
    $processor = makeQuotaStateProcessor(processorCounterStub(throwOnGet: true), PROCESSOR_UUID);

    expect(static fn () => $processor->process(null, synthesisOperation(), ['id' => ARTICLE_ID], []))
        ->toThrow(ServiceUnavailableHttpException::class);
});

test('ServiceUnavailableHttpException contient le message utilisateur approprié', function (): void {
    $processor = makeQuotaStateProcessor(processorCounterStub(throwOnGet: true), PROCESSOR_UUID);

    try {
        $processor->process(null, synthesisOperation(), ['id' => ARTICLE_ID], []);
        expect(true)->toBeFalse('Exception attendue non levée');
    } catch (ServiceUnavailableHttpException $e) {
        expect($e->getMessage())->toContain('temporairement indisponible');
    }
});

test('processor NE génère PAS de synthèse si Redis KO (fail-safe)', function (): void {
    // throwOnGet → consumeOrDeny lève QuotaServiceUnavailableException
    // → ServiceUnavailableHttpException sans appel au inner processor
    $processor = makeQuotaStateProcessor(processorCounterStub(throwOnGet: true), PROCESSOR_UUID);

    $thrown = false;

    try {
        $processor->process(null, synthesisOperation(), ['id' => ARTICLE_ID], []);
    } catch (ServiceUnavailableHttpException) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();
});

// ── Authentification — utilisateur non identifiable ───────────────────────────

test('processor lève AccessDeniedException si aucun utilisateur authentifié', function (): void {
    $processor = makeQuotaStateProcessor(processorCounterStub(), null);

    expect(static fn () => $processor->process(null, synthesisOperation(), ['id' => ARTICLE_ID], []))
        ->toThrow(AccessDeniedException::class);
});
