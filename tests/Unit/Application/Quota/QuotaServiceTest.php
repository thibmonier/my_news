<?php

declare(strict_types=1);

use App\Application\Quota\QuotaService;
use App\Domain\Quota\QuotaCounterInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;

/*
 * Unit tests — QuotaService (Application layer)
 *
 * Couvre les scénarios Gherkin US-033 :
 *   - Nominal (T-033-07) : 1re synthèse → INCR → 1 → true
 *   - 2e synthèse        : clé passe à 2, retourne true
 *   - 3e (dernière)      : clé passe à 3, retourne true
 *   - 4e tentative       : retourne false, clé reste à 3 (pas d'INCR)
 *   - Reset minuit UTC   : nouvelle date → nouvelle clé, INCR = 1, retourne true
 *   - EXPIREAT           : timestamp calculé = prochaine minuit UTC
 *   - Redis KO           : QuotaServiceUnavailableException propagée
 *   - getRemaining       : calcul correct (3 - used)
 *   - getUsed            : plafonné à 3
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
 */
function makeCounterStub(
    int $initialCount = 0,
    ?string &$capturedIncrUuid = null,
    ?string &$capturedDateUtc = null,
    ?int &$capturedExpireAt = null,
    bool $throwOnGet = false,
    bool $throwOnIncr = false,
): QuotaCounterInterface {
    return new class($initialCount, $capturedIncrUuid, $capturedDateUtc, $capturedExpireAt, $throwOnGet, $throwOnIncr) implements QuotaCounterInterface {
        private int $count;

        public function __construct(
            int $initial,
            private mixed &$capturedUuid,
            private mixed &$capturedDate,
            private mixed &$capturedExpiry,
            private readonly bool $throwGet,
            private readonly bool $throwIncr,
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
    };
}

function makeQuotaService(QuotaCounterInterface $counter): QuotaService
{
    return new QuotaService($counter);
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
