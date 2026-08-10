<?php

declare(strict_types=1);

use App\Application\Quota\QuotaService;
use App\Domain\Quota\QuotaCounterInterface;
use App\Domain\Subscription\Subscription;
use App\Domain\Subscription\SubscriptionPlan;
use App\Domain\Subscription\SubscriptionRepositoryInterface;
use App\Domain\Subscription\SubscriptionStatus;
use Symfony\Component\Uid\Uuid;

// T-034b-10 : Tests unitaires QuotaService::isPremium() state machine complète
// + tests unitaires Subscription::isPremium() grace period

// ── Helpers ──────────────────────────────────────────────────────────────────

function makePremiumQuotaService(bool $repoReturns): QuotaService
{
    $repo = new class($repoReturns) implements SubscriptionRepositoryInterface {
        public function __construct(private bool $returns)
        {
        }

        public function save(Subscription $subscription): void
        {
        }

        public function isPremium(string $userUuid): bool
        {
            return $this->returns;
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

        public function findByUserId(string $userUuid): ?Subscription
        {
            return null;
        }
    };

    $counter = new class implements QuotaCounterInterface {
        public function getCount(string $userUuid, string $dateUtc): int
        {
            return 0;
        }

        public function incrementAndExpire(string $userUuid, string $dateUtc, int $expireAtTimestamp): int
        {
            return 0;
        }

        public function decrement(string $userUuid, string $dateUtc): void
        {
        }
    };

    return new QuotaService($counter, $repo);
}

function makePremiumSubscription(
    SubscriptionStatus $status,
    DateTimeImmutable $periodEnd,
    bool $cancelAtPeriodEnd = false,
): Subscription {
    return new Subscription(
        id: Uuid::v4()->toRfc4122(),
        userId: Uuid::v4()->toRfc4122(),
        stripeCustomerId: 'cus_test',
        stripeSubscriptionId: 'sub_test',
        plan: SubscriptionPlan::MONTHLY,
        status: $status,
        currentPeriodEnd: $periodEnd,
        stripeEventId: 'evt_test',
        cancelAtPeriodEnd: $cancelAtPeriodEnd,
    );
}

// ── QuotaService::isPremium() — délégation ────────────────────────────────────

test('QuotaService::isPremium() retourne true quand le repository retourne true', function (): void {
    $service = makePremiumQuotaService(true);
    expect($service->isPremium('user-uuid-1'))->toBeTrue();
});

test('QuotaService::isPremium() retourne false quand le repository retourne false (Free)', function (): void {
    $service = makePremiumQuotaService(false);
    expect($service->isPremium('user-uuid-2'))->toBeFalse();
});

// ── Subscription::isPremium() — state machine ─────────────────────────────────

test('Subscription::isPremium() — status=active + currentPeriodEnd futur → true', function (): void {
    $sub = makePremiumSubscription(
        status: SubscriptionStatus::ACTIVE,
        periodEnd: new DateTimeImmutable('+30 days', new DateTimeZone('UTC')),
    );
    expect($sub->isPremium())->toBeTrue();
});

test('Subscription::isPremium() — status=past_due + currentPeriodEnd dans 2 jours → true (grace period)', function (): void {
    $sub = makePremiumSubscription(
        status: SubscriptionStatus::PAST_DUE,
        periodEnd: new DateTimeImmutable('+2 days', new DateTimeZone('UTC')),
    );
    expect($sub->isPremium())->toBeTrue();
});

test('Subscription::isPremium() — status=past_due + currentPeriodEnd passé → false', function (): void {
    $sub = makePremiumSubscription(
        status: SubscriptionStatus::PAST_DUE,
        periodEnd: new DateTimeImmutable('-1 day', new DateTimeZone('UTC')),
    );
    expect($sub->isPremium())->toBeFalse();
});

test('Subscription::isPremium() — status=cancelled → false (même avec currentPeriodEnd futur)', function (): void {
    $sub = makePremiumSubscription(
        status: SubscriptionStatus::CANCELLED,
        periodEnd: new DateTimeImmutable('+30 days', new DateTimeZone('UTC')),
    );
    expect($sub->isPremium())->toBeFalse();
});

test('Subscription::isPremium() — status=active + cancel_at_period_end=true → true (accès maintenu)', function (): void {
    $sub = makePremiumSubscription(
        status: SubscriptionStatus::ACTIVE,
        periodEnd: new DateTimeImmutable('+15 days', new DateTimeZone('UTC')),
        cancelAtPeriodEnd: true,
    );
    // cancel_at_period_end=true = Thomas a annulé MAIS l'accès reste actif jusqu'à current_period_end
    expect($sub->isPremium())->toBeTrue();
});

test('Subscription::isPremium() — status=active + currentPeriodEnd passé → false', function (): void {
    $sub = makePremiumSubscription(
        status: SubscriptionStatus::ACTIVE,
        periodEnd: new DateTimeImmutable('-1 second', new DateTimeZone('UTC')),
    );
    expect($sub->isPremium())->toBeFalse();
});
