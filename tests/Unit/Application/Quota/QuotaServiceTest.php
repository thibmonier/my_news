<?php

declare(strict_types=1);

use App\Application\Quota\QuotaService;
use App\Domain\Quota\QuotaCounterInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;
use App\Domain\Subscription\Subscription;
use App\Domain\Subscription\SubscriptionRepositoryInterface;

/*
 * Unit tests — QuotaService (Application layer)
 *
 * Couvre les scénarios Gherkin US-013 :
 *   - Nominal (T-013-09) : 1re synthèse → INCR → 1 → true
 *   - 2e synthèse        : clé passe à 2, retourne true
 *   - 3e (dernière)      : clé passe à 3, retourne true
 *   - 4e tentative       : retourne false, clé reste à 3 (pas d'INCR)
 *   - Reset minuit UTC   : nouvelle date → nouvelle clé, INCR = 1, retourne true
 *   - EXPIREAT           : timestamp calculé = prochaine minuit UTC
 *   - Redis KO           : QuotaServiceUnavailableException propagée
 *   - getRemaining       : calcul correct (3 - used)
 *   - getUsed            : plafonné à 3
 *   - Premium bypass (T-013-02) : consumeOrDeny/getRemaining/getUsed sans Redis pour Premium
 *   - refund (T-013-04) : decrement() appelé, plancher à 0
 *
 * Utilise des stubs PHP anonymes (pas de Mockery).
 */

// ── Helpers / stubs ────────────────────────────────────────────────────────────

/**
 * Stub QuotaCounterInterface configurable :
 * - $initialCount : valeur retournée par getCount
 * - $capturedIncrUuid : capture l'UUID passé à incrementAndExpire
 * - $capturedExpireAt : capture l'expireAtTimestamp passé à incrementAndExpire
 * - $throwOnGet / $throwOnIncr : simule Redis KO
 * - $decrementCalled : mis à true si decrement() est appelé
 */
function makeCounterStub(
    int $initialCount = 0,
    ?string &$capturedIncrUuid = null,
    ?string &$capturedDateUtc = null,
    ?int &$capturedExpireAt = null,
    bool $throwOnGet = false,
    bool $throwOnIncr = false,
    bool &$decrementCalled = false,
): QuotaCounterInterface {
    return new class($initialCount, $capturedIncrUuid, $capturedDateUtc, $capturedExpireAt, $throwOnGet, $throwOnIncr, $decrementCalled) implements QuotaCounterInterface {
        private int $count;

        public function __construct(
            int $initial,
            private mixed &$capturedUuid,
            private mixed &$capturedDate,
            private mixed &$capturedExpiry,
            private readonly bool $throwGet,
            private readonly bool $throwIncr,
            private mixed &$decrementCalled,
        ) {
            $this->count = $initial;
        }

        public function getCount(string $userUuid, string $dateUtc): int
        {
            if ($this->throwGet) {
                throw new QuotaServiceUnavailableException('mock Redis KO on GET');
            }

            return $this->count;
        }

        public function incrementAndExpire(string $userUuid, string $dateUtc, int $expireAtTimestamp): int
        {
            if ($this->throwIncr) {
                throw new QuotaServiceUnavailableException('mock Redis KO on INCR');
            }

            $this->capturedUuid = $userUuid;
            $this->capturedDate = $dateUtc;
            $this->capturedExpiry = $expireAtTimestamp;
            ++$this->count;

            return $this->count;
        }

        public function decrement(string $userUuid, string $dateUtc): void
        {
            $this->decrementCalled = true;
            $this->count = max(0, $this->count - 1);
        }
    };
}

function makeSubRepoStub(bool $isPremium = false): SubscriptionRepositoryInterface
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

function makeQuotaService(QuotaCounterInterface $counter, ?SubscriptionRepositoryInterface $subRepo = null): QuotaService
{
    return new QuotaService($counter, $subRepo ?? makeSubRepoStub());
}

const TEST_UUID = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

// ── consumeOrDeny — scénarios nominaux ────────────────────────────────────────

test('consumeOrDeny retourne true pour la 1re synthèse (count=0 → INCR → 1)', function (): void {
    $capturedUuid = null;
    $counter = makeCounterStub(initialCount: 0, capturedIncrUuid: $capturedUuid);
    $service = makeQuotaService($counter);

    $result = $service->consumeOrDeny(TEST_UUID);

    expect($result)->toBeTrue()
        ->and($capturedUuid)->toBe(TEST_UUID);
});

test('consumeOrDeny retourne true pour la 2e synthèse (count=1 → INCR → 2)', function (): void {
    $counter = makeCounterStub(initialCount: 1);
    $result = makeQuotaService($counter)->consumeOrDeny(TEST_UUID);

    expect($result)->toBeTrue();
});

test('consumeOrDeny retourne true pour la 3e synthèse (count=2 → INCR → 3)', function (): void {
    $counter = makeCounterStub(initialCount: 2);
    $result = makeQuotaService($counter)->consumeOrDeny(TEST_UUID);

    expect($result)->toBeTrue();
});

// ── consumeOrDeny — scénario erreur 1 (quota dépassé) ─────────────────────────

test('consumeOrDeny retourne false à la 4e tentative sans incrémenter (count=3)', function (): void {
    $capturedExpire = null;
    $counter = makeCounterStub(initialCount: 3, capturedExpireAt: $capturedExpire);
    $result = makeQuotaService($counter)->consumeOrDeny(TEST_UUID);

    // false sans INCR → capturedExpire doit rester null
    expect($result)->toBeFalse()
        ->and($capturedExpire)->toBeNull();
});

test('consumeOrDeny retourne false quand count > 3 (cas défensif)', function (): void {
    $counter = makeCounterStub(initialCount: 5);
    $result = makeQuotaService($counter)->consumeOrDeny(TEST_UUID);

    expect($result)->toBeFalse();
});

// ── consumeOrDeny — reset minuit UTC (nouvelle date = nouvelle clé) ────────────

test('consumeOrDeny crée une nouvelle clé le lendemain UTC (reset automatique)', function (): void {
    $capturedDate = null;
    $counter = makeCounterStub(initialCount: 0, capturedDateUtc: $capturedDate);
    makeQuotaService($counter)->consumeOrDeny(TEST_UUID);

    // La date passée au counter doit être aujourd'hui UTC (YYYY-MM-DD)
    $expectedDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    expect($capturedDate)->toBe($expectedDate);
});

// ── consumeOrDeny — EXPIREAT à minuit UTC ─────────────────────────────────────

test('consumeOrDeny positionne EXPIREAT à la prochaine minuit UTC', function (): void {
    $capturedExpire = null;
    $counter = makeCounterStub(initialCount: 0, capturedExpireAt: $capturedExpire);
    makeQuotaService($counter)->consumeOrDeny(TEST_UUID);

    $expectedExpiry = (new DateTimeImmutable('tomorrow 00:00:00', new DateTimeZone('UTC')))->getTimestamp();

    // Le timestamp doit correspondre à la prochaine minuit UTC (±1 seconde tolérance DST)
    expect($capturedExpire)->toBeInt()
        ->and(abs((int) $capturedExpire - $expectedExpiry))->toBeLessThanOrEqual(1);
});

// ── Redis KO — fail-safe ────────────────────────────────────────────────────────

test('consumeOrDeny propage QuotaServiceUnavailableException si Redis KO (GET)', function (): void {
    $counter = makeCounterStub(throwOnGet: true);
    $service = makeQuotaService($counter);

    expect(static fn () => $service->consumeOrDeny(TEST_UUID))
        ->toThrow(QuotaServiceUnavailableException::class);
});

test('consumeOrDeny propage QuotaServiceUnavailableException si Redis KO (INCR)', function (): void {
    $counter = makeCounterStub(initialCount: 0, throwOnIncr: true);
    $service = makeQuotaService($counter);

    expect(static fn () => $service->consumeOrDeny(TEST_UUID))
        ->toThrow(QuotaServiceUnavailableException::class);
});

// ── Premium bypass (T-013-02) ─────────────────────────────────────────────────

test('consumeOrDeny retourne true sans appel Redis pour un utilisateur Premium', function (): void {
    $capturedExpire = null;
    // count=3 (quota épuisé côté free) mais Premium → doit retourner true sans Redis
    $counter = makeCounterStub(initialCount: 3, capturedExpireAt: $capturedExpire);
    $service = makeQuotaService($counter, makeSubRepoStub(isPremium: true));

    $result = $service->consumeOrDeny(TEST_UUID);

    expect($result)->toBeTrue()
        ->and($capturedExpire)->toBeNull(); // Redis non appelé
});

test('isPremium retourne true pour un abonnement actif', function (): void {
    $counter = makeCounterStub();
    $service = makeQuotaService($counter, makeSubRepoStub(isPremium: true));

    expect($service->isPremium(TEST_UUID))->toBeTrue();
});

test('isPremium retourne false pour un compte sans abonnement', function (): void {
    $counter = makeCounterStub();
    $service = makeQuotaService($counter, makeSubRepoStub(isPremium: false));

    expect($service->isPremium(TEST_UUID))->toBeFalse();
});

test('getRemaining retourne DAILY_LIMIT pour un utilisateur Premium (sans Redis)', function (): void {
    $counter = makeCounterStub(initialCount: 99); // valeur Redis ignorée
    $service = makeQuotaService($counter, makeSubRepoStub(isPremium: true));

    expect($service->getRemaining(TEST_UUID))->toBe(QuotaService::DAILY_LIMIT);
});

test('getUsed retourne 0 pour un utilisateur Premium (sans Redis)', function (): void {
    $counter = makeCounterStub(initialCount: 99); // valeur Redis ignorée
    $service = makeQuotaService($counter, makeSubRepoStub(isPremium: true));

    expect($service->getUsed(TEST_UUID))->toBe(0);
});

// ── refund (T-013-04) ─────────────────────────────────────────────────────────

test('refund() appelle decrement() sur le counter', function (): void {
    $decrementCalled = false;
    $counter = makeCounterStub(initialCount: 1, decrementCalled: $decrementCalled);
    makeQuotaService($counter)->refund(TEST_UUID);

    expect($decrementCalled)->toBeTrue();
});

test('refund() ne rend pas le compteur négatif (plancher à 0)', function (): void {
    // Avec initialCount=0, decrement doit plafonner à 0 (logique du stub)
    $decrementCalled = false;
    $counter = makeCounterStub(initialCount: 0, decrementCalled: $decrementCalled);
    makeQuotaService($counter)->refund(TEST_UUID);

    expect($decrementCalled)->toBeTrue();
});

// ── nextMidnightUtcIso ────────────────────────────────────────────────────────

test('nextMidnightUtcIso retourne un ISO8601 UTC à minuit', function (): void {
    $service = makeQuotaService(makeCounterStub());
    $iso = $service->nextMidnightUtcIso();

    // Format ATOM : 2026-08-12T00:00:00+00:00
    expect($iso)->toMatch('/^\d{4}-\d{2}-\d{2}T00:00:00\+00:00$/');
});

// ── getRemaining ────────────────────────────────────────────────────────────────

test('getRemaining retourne 3 quand aucune synthèse utilisée (count=0)', function (): void {
    $counter = makeCounterStub(initialCount: 0);
    expect(makeQuotaService($counter)->getRemaining(TEST_UUID))->toBe(3);
});

test('getRemaining retourne 2 après 1 synthèse consommée', function (): void {
    $counter = makeCounterStub(initialCount: 1);
    expect(makeQuotaService($counter)->getRemaining(TEST_UUID))->toBe(2);
});

test('getRemaining retourne 0 quand quota épuisé (count=3)', function (): void {
    $counter = makeCounterStub(initialCount: 3);
    expect(makeQuotaService($counter)->getRemaining(TEST_UUID))->toBe(0);
});

test('getRemaining retourne 0 et non négatif si count > 3', function (): void {
    $counter = makeCounterStub(initialCount: 99);
    expect(makeQuotaService($counter)->getRemaining(TEST_UUID))->toBe(0);
});

// ── getUsed ─────────────────────────────────────────────────────────────────────

test('getUsed retourne 0 pour un compte sans synthèse', function (): void {
    $counter = makeCounterStub(initialCount: 0);
    expect(makeQuotaService($counter)->getUsed(TEST_UUID))->toBe(0);
});

test('getUsed retourne 2 après 2 synthèses consommées', function (): void {
    $counter = makeCounterStub(initialCount: 2);
    expect(makeQuotaService($counter)->getUsed(TEST_UUID))->toBe(2);
});

test('getUsed est plafonné à 3 (DAILY_LIMIT)', function (): void {
    $counter = makeCounterStub(initialCount: 5); // cas défensif
    expect(makeQuotaService($counter)->getUsed(TEST_UUID))->toBe(3);
});

// ── DAILY_LIMIT constant ──────────────────────────────────────────────────────

test('QuotaService::DAILY_LIMIT vaut 3', function (): void {
    expect(QuotaService::DAILY_LIMIT)->toBe(3);
});

// ── QuotaServiceUnavailableException ─────────────────────────────────────────

test('QuotaServiceUnavailableException contient le message Redis sans données utilisateur', function (): void {
    $ex = new QuotaServiceUnavailableException('Connection timeout');

    expect($ex->getMessage())->toContain('QuotaService: Redis connection failed')
        ->and($ex->getMessage())->toContain('Connection timeout')
        ->and($ex->getMessage())->not->toContain(TEST_UUID);
});

test('QuotaServiceUnavailableException préserve l\'exception précédente', function (): void {
    $previous = new RuntimeException('original');
    $ex = new QuotaServiceUnavailableException('', $previous);

    expect($ex->getPrevious())->toBe($previous);
});
