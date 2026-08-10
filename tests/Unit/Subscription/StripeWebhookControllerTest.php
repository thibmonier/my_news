<?php

declare(strict_types=1);

use App\Application\Subscription\StripeWebhookMessage;
use App\Presentation\Controller\Stripe\StripeWebhookController;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

// T-034a-12 : Tests unitaires StripeWebhookController — HMAC validation

/**
 * Construit un header Stripe-Signature HMAC-SHA256 valide (comme le ferait Stripe).
 * Permet de tester la validation HMAC sans compte Stripe réel.
 */
function buildStripeSignature(string $payload, string $secret, int $timestamp): string
{
    $signedPayload = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return "t={$timestamp},v1={$signature}";
}

function makeTestBus(int &$dispatchCount): MessageBusInterface
{
    return new class($dispatchCount) implements MessageBusInterface {
        public function __construct(private int &$count)
        {
        }

        public function dispatch(object $message, array $stamps = []): Envelope
        {
            ++$this->count;

            return new Envelope($message);
        }
    };
}

function makeCapturingBus(mixed &$captured): MessageBusInterface
{
    return new class($captured) implements MessageBusInterface {
        public function __construct(private mixed &$captured)
        {
        }

        public function dispatch(object $message, array $stamps = []): Envelope
        {
            $this->captured = $message;

            return new Envelope($message);
        }
    };
}

function makeNullBus(): MessageBusInterface
{
    return new class implements MessageBusInterface {
        public function dispatch(object $message, array $stamps = []): Envelope
        {
            return new Envelope($message);
        }
    };
}

function makeCapturingLogger(array &$warnings): AbstractLogger
{
    return new class($warnings) extends AbstractLogger {
        public function __construct(private array &$warnings)
        {
        }

        /**
         * @param mixed[] $context
         */
        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            if ('warning' === $level) {
                $this->warnings[] = ['message' => (string) $message, 'context' => $context];
            }
        }
    };
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test('HMAC valide → HTTP 200 + dispatch() appelé 1 fois', function (): void {
    $secret = 'whsec_test_secret_32chars_minimum_x';
    $payload = json_encode(['type' => 'checkout.session.completed', 'id' => 'evt_test', 'data' => ['object' => []]]);
    assert(is_string($payload));
    $timestamp = time();
    $sigHeader = buildStripeSignature($payload, $secret, $timestamp);

    $dispatchCount = 0;
    $bus = makeTestBus($dispatchCount);

    $controller = new StripeWebhookController($bus, new Psr\Log\NullLogger(), $secret);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [], $payload);
    $request->headers->set('Stripe-Signature', $sigHeader);

    $response = $controller->handle($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($dispatchCount)->toBe(1);
});

test('signature invalide (header corrompu) → HTTP 400 + 0 dispatch + log WARNING', function (): void {
    $secret = 'whsec_test_secret_32chars_minimum_x';
    $payload = json_encode(['type' => 'checkout.session.completed', 'id' => 'evt_test', 'data' => ['object' => []]]);
    assert(is_string($payload));

    $dispatchCount = 0;
    $bus = makeTestBus($dispatchCount);
    $warnings = [];
    $logger = makeCapturingLogger($warnings);

    $controller = new StripeWebhookController($bus, $logger, $secret);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [], $payload);
    $request->headers->set('Stripe-Signature', 't=123,v1=invalidsignature');

    $response = $controller->handle($request);

    expect($response->getStatusCode())->toBe(400)
        ->and($dispatchCount)->toBe(0)
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0]['context']['reason'])->toBe('invalid_stripe_signature');
});

test('header Stripe-Signature absent → HTTP 400 + 0 dispatch', function (): void {
    $secret = 'whsec_test_secret_32chars_minimum_x';
    $payload = '{"type":"test"}';

    $dispatchCount = 0;
    $bus = makeTestBus($dispatchCount);

    $controller = new StripeWebhookController($bus, new Psr\Log\NullLogger(), $secret);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [], $payload);
    // Pas de header Stripe-Signature

    $response = $controller->handle($request);

    expect($response->getStatusCode())->toBe(400)
        ->and($dispatchCount)->toBe(0);
});

test('payload vide → HTTP 400 + 0 dispatch', function (): void {
    $secret = 'whsec_test_secret_32chars_minimum_x';

    $dispatchCount = 0;
    $bus = makeTestBus($dispatchCount);

    $controller = new StripeWebhookController($bus, new Psr\Log\NullLogger(), $secret);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [], '');
    $request->headers->set('Stripe-Signature', 't=123,v1=abc');

    $response = $controller->handle($request);

    expect($response->getStatusCode())->toBe(400)
        ->and($dispatchCount)->toBe(0);
});

test('payload brut n\'apparaît jamais dans les logs WARNING', function (): void {
    $secret = 'whsec_test_secret_32chars_minimum_x';
    $sensiblePayload = json_encode([
        'type' => 'checkout.session.completed',
        'id' => 'evt_test',
        'data' => ['object' => ['customer_email' => 'thomas@example.com', 'card_number' => '4242424242424242']],
    ]);
    assert(is_string($sensiblePayload));

    $warnings = [];
    $logger = makeCapturingLogger($warnings);

    $controller = new StripeWebhookController(makeNullBus(), $logger, $secret);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [], $sensiblePayload);
    $request->headers->set('Stripe-Signature', 't=123,v1=invalidsignature');

    $controller->handle($request);

    // Vérifier qu'aucun warning ne contient le payload brut ou des données sensibles
    foreach ($warnings as $warning) {
        $logStr = json_encode($warning);
        assert(is_string($logStr));
        expect($logStr)->not->toContain('thomas@example.com')
            ->and($logStr)->not->toContain('4242424242424242')
            ->and($logStr)->not->toContain('customer_email');
    }

    expect($warnings)->toHaveCount(1); // Un seul warning pour signature invalide
});

test('HMAC valide → eventType et eventId correctement transmis au bus', function (): void {
    $secret = 'whsec_test_secret_32chars_minimum_x';
    $payload = json_encode([
        'type' => 'checkout.session.completed',
        'id' => 'evt_captured123',
        'data' => ['object' => ['customer' => 'cus_abc', 'subscription' => 'sub_xyz']],
    ]);
    assert(is_string($payload));
    $timestamp = time();
    $sigHeader = buildStripeSignature($payload, $secret, $timestamp);

    $captured = null;
    $bus = makeCapturingBus($captured);

    $controller = new StripeWebhookController($bus, new Psr\Log\NullLogger(), $secret);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [], $payload);
    $request->headers->set('Stripe-Signature', $sigHeader);

    $controller->handle($request);

    expect($captured)->toBeInstanceOf(StripeWebhookMessage::class)
        ->and($captured->eventType)->toBe('checkout.session.completed')
        ->and($captured->eventId)->toBe('evt_captured123');
});
