<?php

declare(strict_types=1);

use App\Application\Subscription\StripeWebhookMessage;
use App\Domain\Subscription\Subscription;
use App\Domain\Subscription\SubscriptionPlan;
use App\Domain\Subscription\SubscriptionRepositoryInterface;
use App\Domain\Subscription\SubscriptionStatus;
use App\Infrastructure\Subscription\Messenger\SubscriptionActivatedHandler;
use Psr\Log\NullLogger;

// T-034a-11 : Tests unitaires SubscriptionActivatedHandler

test('checkout.session.completed valide → save() appelé une fois avec status active', function (): void {
    $repository = new class implements SubscriptionRepositoryInterface {
        public int $saveCalled = 0;
        public ?Subscription $saved = null;

        public function save(Subscription $subscription): void
        {
            ++$this->saveCalled;
            $this->saved = $subscription;
        }

        public function isPremium(string $userUuid): bool
        {
            return false;
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

    $handler = new SubscriptionActivatedHandler($repository, new NullLogger());

    $message = new StripeWebhookMessage(
        eventType: 'checkout.session.completed',
        eventId: 'evt_1ABCDEF',
        payload: [
            'customer' => 'cus_test123',
            'subscription' => 'sub_test123',
            'client_reference_id' => 'user-uuid-1234',
            'metadata' => ['plan' => 'monthly'],
        ],
    );

    $handler($message);

    expect($repository->saveCalled)->toBe(1)
        ->and($repository->saved)->not->toBeNull()
        ->and($repository->saved->getStatus())->toBe(SubscriptionStatus::ACTIVE)
        ->and($repository->saved->getPlan())->toBe(SubscriptionPlan::MONTHLY)
        ->and($repository->saved->getStripeEventId())->toBe('evt_1ABCDEF')
        ->and($repository->saved->getStripeCustomerId())->toBe('cus_test123')
        ->and($repository->saved->getUserId())->toBe('user-uuid-1234');
});

test('plan yearly extrait correctement depuis metadata', function (): void {
    $repository = new class implements SubscriptionRepositoryInterface {
        public ?Subscription $saved = null;

        public function save(Subscription $subscription): void
        {
            $this->saved = $subscription;
        }

        public function isPremium(string $userUuid): bool
        {
            return false;
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

    $handler = new SubscriptionActivatedHandler($repository, new NullLogger());

    $handler(new StripeWebhookMessage(
        eventType: 'checkout.session.completed',
        eventId: 'evt_YEARLY',
        payload: [
            'customer' => 'cus_456',
            'subscription' => 'sub_456',
            'client_reference_id' => 'user-uuid-5678',
            'metadata' => ['plan' => 'yearly'],
        ],
    ));

    expect($repository->saved)->not->toBeNull()
        ->and($repository->saved->getPlan())->toBe(SubscriptionPlan::YEARLY);
});

test('autre type d\'événement (invoice.paid) → handler retourne sans appel save()', function (): void {
    $repository = new class implements SubscriptionRepositoryInterface {
        public int $saveCalled = 0;

        public function save(Subscription $subscription): void
        {
            ++$this->saveCalled;
        }

        public function isPremium(string $userUuid): bool
        {
            return false;
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

    $handler = new SubscriptionActivatedHandler($repository, new NullLogger());

    $handler(new StripeWebhookMessage(
        eventType: 'invoice.paid',
        eventId: 'evt_INVOICE',
        payload: ['amount_paid' => 1200],
    ));

    expect($repository->saveCalled)->toBe(0);
});

test('subscription.updated ignoré → 0 appels save()', function (): void {
    $repository = new class implements SubscriptionRepositoryInterface {
        public int $saveCalled = 0;

        public function save(Subscription $subscription): void
        {
            ++$this->saveCalled;
        }

        public function isPremium(string $userUuid): bool
        {
            return false;
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

    $handler = new SubscriptionActivatedHandler($repository, new NullLogger());

    $handler(new StripeWebhookMessage(
        eventType: 'customer.subscription.updated',
        eventId: 'evt_UPDATE',
        payload: [],
    ));

    expect($repository->saveCalled)->toBe(0);
});
