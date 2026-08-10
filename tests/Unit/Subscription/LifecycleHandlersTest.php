<?php

declare(strict_types=1);

use App\Application\Subscription\StripeWebhookMessage;
use App\Domain\Subscription\Subscription;
use App\Infrastructure\Subscription\Messenger\PaymentFailedHandler;
use App\Infrastructure\Subscription\Messenger\SubscriptionCancelledHandler;
use App\Infrastructure\Subscription\Messenger\SubscriptionUpdatedHandler;
use App\Infrastructure\Subscription\Persistence\DoctrineSubscriptionRepository;
use Psr\Log\AbstractLogger;

// T-034b-09 : Tests unitaires SubscriptionCancelledHandler, SubscriptionUpdatedHandler, PaymentFailedHandler

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Crée un mock minimal de DoctrineSubscriptionRepository qui simule updateByStripeSubscriptionId().
 *
 * @param bool $found true = 1 ligne affectée, false = 0 ligne
 * @param array<string, mixed> $capturedFields champ par référence pour capturer les arguments
 * @param string $capturedSubId champ par référence pour capturer stripe_subscription_id
 */
function makeRepoStub(bool $found, array &$capturedFields = [], string &$capturedSubId = ''): DoctrineSubscriptionRepository
{
    return new class($found, $capturedFields, $capturedSubId) extends DoctrineSubscriptionRepository {
        public function __construct(
            private bool $found,
            private array &$capturedFields,
            private string &$capturedSubId,
        ) {
            // Pas d'appel parent — on bypass totalement la construction Doctrine
        }

        public function updateByStripeSubscriptionId(string $stripeSubscriptionId, array $fields): bool
        {
            $this->capturedSubId = $stripeSubscriptionId;
            $this->capturedFields = $fields;

            return $this->found;
        }

        public function save(Subscription $subscription): void
        {
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
}

/**
 * Crée un logger capturant les messages WARNING.
 *
 * @param array<int, array{level: string, message: string, context: array<string, mixed>}> $captured
 */
function makeWarningLogger(array &$captured): AbstractLogger
{
    return new class($captured) extends AbstractLogger {
        /** @param array<int, array{level: string, message: string, context: array<string, mixed>}> $captured */
        public function __construct(private array &$captured)
        {
        }

        /** @param mixed[] $context */
        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->captured[] = [
                'level' => (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    };
}

// ── SubscriptionCancelledHandler ──────────────────────────────────────────────

test('SubscriptionCancelledHandler: sub trouvé → status=cancelled mis en base', function (): void {
    $capturedFields = [];
    $capturedSubId = '';
    $repo = makeRepoStub(found: true, capturedFields: $capturedFields, capturedSubId: $capturedSubId);

    $handler = new SubscriptionCancelledHandler($repo, new Psr\Log\NullLogger());

    $handler(new StripeWebhookMessage(
        eventType: 'customer.subscription.deleted',
        eventId: 'evt_cancel_1',
        payload: ['id' => 'sub_ABC'],
    ));

    expect($capturedSubId)->toBe('sub_ABC')
        ->and($capturedFields)->toBe(['status' => 'cancelled']);
});

test('SubscriptionCancelledHandler: sub inconnu → 0 erreur + log WARNING skip-not-found', function (): void {
    $capturedFields = [];
    $capturedSubId = '';
    $warnings = [];
    $repo = makeRepoStub(found: false, capturedFields: $capturedFields, capturedSubId: $capturedSubId);
    $logger = makeWarningLogger($warnings);

    $handler = new SubscriptionCancelledHandler($repo, $logger);

    // Ne doit pas lever d'exception
    $handler(new StripeWebhookMessage(
        eventType: 'customer.subscription.deleted',
        eventId: 'evt_cancel_unknown',
        payload: ['id' => 'sub_UNKNOWN'],
    ));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['context']['action'])->toBe('skip-not-found')
        ->and($warnings[0]['context']['stripe_subscription_id'])->toBe('sub_UNKNOWN');
});

test('SubscriptionCancelledHandler: autre type → ignoré sans appel repository', function (): void {
    $capturedFields = [];
    $capturedSubId = '';
    $repo = makeRepoStub(found: true, capturedFields: $capturedFields, capturedSubId: $capturedSubId);

    $handler = new SubscriptionCancelledHandler($repo, new Psr\Log\NullLogger());

    $handler(new StripeWebhookMessage(
        eventType: 'customer.subscription.updated',
        eventId: 'evt_other',
        payload: ['id' => 'sub_OTHER'],
    ));

    // Aucun appel : capturedSubId reste vide
    expect($capturedSubId)->toBe('');
});

// ── SubscriptionUpdatedHandler ─────────────────────────────────────────────────

test('SubscriptionUpdatedHandler: cancel_at_period_end=true → champ mis à jour en base', function (): void {
    $capturedFields = [];
    $capturedSubId = '';
    $repo = makeRepoStub(found: true, capturedFields: $capturedFields, capturedSubId: $capturedSubId);

    $handler = new SubscriptionUpdatedHandler($repo, new Psr\Log\NullLogger());

    $handler(new StripeWebhookMessage(
        eventType: 'customer.subscription.updated',
        eventId: 'evt_update_1',
        payload: [
            'id' => 'sub_XYZ',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'current_period_end' => 1800000000,
        ],
    ));

    expect($capturedSubId)->toBe('sub_XYZ')
        ->and($capturedFields['cancel_at_period_end'])->toBe('true')
        ->and($capturedFields['status'])->toBe('active');
});

test('SubscriptionUpdatedHandler: sub inconnu → 0 erreur + log WARNING', function (): void {
    $capturedFields = [];
    $capturedSubId = '';
    $warnings = [];
    $repo = makeRepoStub(found: false, capturedFields: $capturedFields, capturedSubId: $capturedSubId);
    $logger = makeWarningLogger($warnings);

    $handler = new SubscriptionUpdatedHandler($repo, $logger);

    $handler(new StripeWebhookMessage(
        eventType: 'customer.subscription.updated',
        eventId: 'evt_unknown',
        payload: ['id' => 'sub_UNKNOWN', 'status' => 'active'],
    ));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['context']['action'])->toBe('skip-not-found');
});

test('SubscriptionUpdatedHandler: autre type → ignoré sans appel repository', function (): void {
    $capturedFields = [];
    $capturedSubId = '';
    $repo = makeRepoStub(found: true, capturedFields: $capturedFields, capturedSubId: $capturedSubId);

    $handler = new SubscriptionUpdatedHandler($repo, new Psr\Log\NullLogger());

    $handler(new StripeWebhookMessage(
        eventType: 'invoice.payment_failed',
        eventId: 'evt_other',
        payload: ['id' => 'sub_OTHER'],
    ));

    expect($capturedSubId)->toBe('');
});

// ── PaymentFailedHandler ───────────────────────────────────────────────────────

test('PaymentFailedHandler: status=past_due + log WARNING UUID (0 email)', function (): void {
    $capturedFields = [];
    $capturedSubId = '';
    $logs = [];
    $repo = makeRepoStub(found: true, capturedFields: $capturedFields, capturedSubId: $capturedSubId);
    $logger = makeWarningLogger($logs);

    $handler = new PaymentFailedHandler($repo, $logger);

    $handler(new StripeWebhookMessage(
        eventType: 'invoice.payment_failed',
        eventId: 'evt_pf_1',
        payload: ['subscription' => 'sub_PF123'],
    ));

    expect($capturedSubId)->toBe('sub_PF123')
        ->and($capturedFields)->toBe(['status' => 'past_due'])
        // Log WARNING avec event_id + action, 0 email
        ->and($logs)->toHaveCount(1)
        ->and($logs[0]['context']['action'])->toBe('past_due')
        ->and($logs[0]['context']['event_id'])->toBe('evt_pf_1');

    // Vérification qu'aucun log ne contient d'email
    $logStr = json_encode($logs);
    assert(is_string($logStr));
    expect($logStr)->not->toContain('@');
});

test('PaymentFailedHandler: sub inconnu → 0 erreur + log WARNING skip-not-found', function (): void {
    $capturedFields = [];
    $capturedSubId = '';
    $warnings = [];
    $repo = makeRepoStub(found: false, capturedFields: $capturedFields, capturedSubId: $capturedSubId);
    $logger = makeWarningLogger($warnings);

    $handler = new PaymentFailedHandler($repo, $logger);

    $handler(new StripeWebhookMessage(
        eventType: 'invoice.payment_failed',
        eventId: 'evt_pf_unknown',
        payload: ['subscription' => 'sub_UNKNOWN'],
    ));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['context']['action'])->toBe('skip-not-found');
});

test('PaymentFailedHandler: autre type → ignoré sans appel repository', function (): void {
    $capturedFields = [];
    $capturedSubId = '';
    $repo = makeRepoStub(found: true, capturedFields: $capturedFields, capturedSubId: $capturedSubId);

    $handler = new PaymentFailedHandler($repo, new Psr\Log\NullLogger());

    $handler(new StripeWebhookMessage(
        eventType: 'checkout.session.completed',
        eventId: 'evt_other',
        payload: ['subscription' => 'sub_OTHER'],
    ));

    expect($capturedSubId)->toBe('');
});
