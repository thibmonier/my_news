<?php

declare(strict_types=1);

use App\Infrastructure\User\Persistence\DoctrineUserEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Stub\StripeGatewayStub;

// T-034a-13 : WebTestCase — Page Premium + Webhook Stripe + Idempotence

uses(WebTestCase::class);

beforeEach(function (): void {
    static::ensureKernelShutdown();
});

afterEach(function (): void {
    static::ensureKernelShutdown();
});

/**
 * Calcule un header Stripe-Signature HMAC-SHA256 valide (même algorithme que Stripe).
 */
function featureBuildStripeSignature(string $payload, string $secret, int $timestamp): string
{
    $signedPayload = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return "t={$timestamp},v1={$signature}";
}

/**
 * Crée un utilisateur en base via le formulaire d'inscription.
 */
function createTestUser(KernelBrowser $client, string $email): DoctrineUserEntity
{
    $client->request('GET', '/register');
    $content = (string) $client->getResponse()->getContent();
    preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $content, $csrfMatches);
    $csrfToken = $csrfMatches[1] ?? 'invalid';

    $client->request('POST', '/register', [
        'email' => $email,
        'fullName' => 'Test Premium User',
        'plainPassword' => 'TestPassword#2026!',
        'consentCgu' => '1',
        '_csrf_token' => $csrfToken,
    ]);

    if (302 !== $client->getResponse()->getStatusCode()) {
        throw new RuntimeException("Registration failed for {$email}: " . $client->getResponse()->getStatusCode());
    }

    /** @var EntityManagerInterface $em */
    $em = $client->getContainer()->get('doctrine.orm.entity_manager');
    $em->clear();

    $entity = $em->getRepository(DoctrineUserEntity::class)->findOneBy(['email' => $email]);
    if (null === $entity) {
        throw new RuntimeException("User not found after registration: {$email}");
    }

    return $entity;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test('GET /premium → HTTP 200 avec tarifs 12€/mois et 99€/an', function (): void {
    $client = static::createClient();
    $email = 'premium_' . uniqid() . '@test.com';
    $user = createTestUser($client, $email);

    $client->loginUser($user);
    $client->request('GET', '/premium');

    $content = (string) $client->getResponse()->getContent();
    expect($client->getResponse()->getStatusCode())->toBe(200)
        ->and($content)->toContain('12')    // prix mensuel
        ->and($content)->toContain('99')    // prix annuel
        ->and($content)->toContain('monthly')
        ->and($content)->toContain('yearly');
});

test('GET /premium sans auth → redirect vers login', function (): void {
    $client = static::createClient();
    $client->request('GET', '/premium');

    expect($client->getResponse()->getStatusCode())->toBe(302);
    expect($client->getResponse()->headers->get('Location'))->toContain('/login');
});

test('GET /premium/checkout/monthly → redirect 302 vers URL Stripe (stub gateway)', function (): void {
    // Le stub StripeGatewayStub est enregistré en APP_ENV=test (config/services_test.yaml)
    // Il retourne une URL fictive sans appeler l'API Stripe réelle.
    $client = static::createClient();
    $email = 'checkout_monthly_' . uniqid() . '@test.com';
    $user = createTestUser($client, $email);

    $client->loginUser($user);
    $client->request('GET', '/premium/checkout/monthly');

    expect($client->getResponse()->getStatusCode())->toBe(302)
        ->and((string) $client->getResponse()->headers->get('Location'))
        ->toContain(StripeGatewayStub::TEST_CHECKOUT_URL);
});

test('GET /premium/checkout/yearly → redirect 302 vers URL Stripe (stub gateway)', function (): void {
    $client = static::createClient();
    $email = 'checkout_yearly_' . uniqid() . '@test.com';
    $user = createTestUser($client, $email);

    $client->loginUser($user);
    $client->request('GET', '/premium/checkout/yearly');

    expect($client->getResponse()->getStatusCode())->toBe(302)
        ->and((string) $client->getResponse()->headers->get('Location'))
        ->toContain(StripeGatewayStub::TEST_CHECKOUT_URL);
});

test('GET /premium/checkout/invalid → HTTP 400', function (): void {
    $client = static::createClient();
    $email = 'checkout_invalid_' . uniqid() . '@test.com';
    $user = createTestUser($client, $email);

    $client->loginUser($user);
    $client->request('GET', '/premium/checkout/invalid');

    expect($client->getResponse()->getStatusCode())->toBe(400);
});

test('GET /premium/checkout/enterprise → HTTP 400 (plan inconnu)', function (): void {
    $client = static::createClient();
    $email = 'checkout_enterprise_' . uniqid() . '@test.com';
    $user = createTestUser($client, $email);

    $client->loginUser($user);
    $client->request('GET', '/premium/checkout/enterprise');

    expect($client->getResponse()->getStatusCode())->toBe(400);
});

test('POST /stripe/webhook HMAC valide + checkout.session.completed → HTTP 200 + 1 souscription créée', function (): void {
    $client = static::createClient();
    $email = 'webhook_valid_' . uniqid() . '@test.com';
    $user = createTestUser($client, $email);
    $userId = $user->getUserUuid();

    $webhookSecret = $_SERVER['STRIPE_WEBHOOK_SECRET'] ?? 'whsec_test_webhook_secret_for_tests_32chars';
    $eventId = 'evt_test_' . uniqid();

    $payload = json_encode([
        'type' => 'checkout.session.completed',
        'id' => $eventId,
        'data' => [
            'object' => [
                'customer' => 'cus_test_' . uniqid(),
                'subscription' => 'sub_test_' . uniqid(),
                'client_reference_id' => $userId,
                'metadata' => ['plan' => 'monthly'],
            ],
        ],
    ]);
    assert(is_string($payload));

    $timestamp = time();
    $sigHeader = featureBuildStripeSignature($payload, $webhookSecret, $timestamp);

    $client->request(
        'POST',
        '/stripe/webhook',
        [],
        [],
        ['HTTP_Stripe-Signature' => $sigHeader, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    expect($client->getResponse()->getStatusCode())->toBe(200);

    // Forcer le traitement synchrone des messages Messenger en test
    /** @var Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $transport */
    $transport = $client->getContainer()->get('messenger.transport.async');
    $messages = $transport->get();

    expect($messages)->toHaveCount(1);
});

test('POST /stripe/webhook même event en double → HTTP 200 + idempotence (1 seul enregistrement)', function (): void {
    $client = static::createClient();
    $email = 'webhook_idempotent_' . uniqid() . '@test.com';
    $user = createTestUser($client, $email);
    $userId = $user->getUserUuid();

    $webhookSecret = $_SERVER['STRIPE_WEBHOOK_SECRET'] ?? 'whsec_test_webhook_secret_for_tests_32chars';
    $eventId = 'evt_idempotent_' . uniqid();
    $customerId = 'cus_idem_' . uniqid();
    $subscriptionId = 'sub_idem_' . uniqid();

    $payload = json_encode([
        'type' => 'checkout.session.completed',
        'id' => $eventId,
        'data' => [
            'object' => [
                'customer' => $customerId,
                'subscription' => $subscriptionId,
                'client_reference_id' => $userId,
                'metadata' => ['plan' => 'monthly'],
            ],
        ],
    ]);
    assert(is_string($payload));

    // Insérer manuellement un abonnement avec le même stripe_event_id
    /** @var Doctrine\DBAL\Connection $connection */
    $connection = $client->getContainer()->get('doctrine.dbal.default_connection');
    $connection->executeStatement(
        'INSERT INTO subscriptions (id, user_id, stripe_customer_id, stripe_subscription_id, plan, status, current_period_end, stripe_event_id, created_at)
         VALUES (:id, :userId, :customerId, :subscriptionId, :plan, :status, :periodEnd, :eventId, :createdAt)',
        [
            'id' => Uuid::v4()->toRfc4122(),
            'userId' => $userId,
            'customerId' => $customerId,
            'subscriptionId' => $subscriptionId,
            'plan' => 'monthly',
            'status' => 'active',
            'periodEnd' => (new DateTimeImmutable('+30 days'))->format('Y-m-d H:i:sO'),
            'eventId' => $eventId,
            'createdAt' => (new DateTimeImmutable())->format('Y-m-d H:i:sO'),
        ],
    );

    $timestamp = time();
    $sigHeader = featureBuildStripeSignature($payload, $webhookSecret, $timestamp);

    // Envoyer le même webhook
    $client->request(
        'POST',
        '/stripe/webhook',
        [],
        [],
        ['HTTP_Stripe-Signature' => $sigHeader, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    expect($client->getResponse()->getStatusCode())->toBe(200);

    // Vérifier qu'il n'y a toujours qu'1 enregistrement avec cet eventId
    $count = $connection->fetchOne(
        'SELECT COUNT(*) FROM subscriptions WHERE stripe_event_id = :eventId',
        ['eventId' => $eventId],
    );

    expect((int) $count)->toBe(1);
});

test('POST /stripe/webhook signature invalide → HTTP 400', function (): void {
    $client = static::createClient();

    $payload = '{"type":"checkout.session.completed","id":"evt_test","data":{"object":{}}}';

    $client->request(
        'POST',
        '/stripe/webhook',
        [],
        [],
        ['HTTP_Stripe-Signature' => 't=123,v1=invalidsignature', 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    expect($client->getResponse()->getStatusCode())->toBe(400);
});

test('GET /dashboard?checkout=success → flash premium visible', function (): void {
    $client = static::createClient();
    $email = 'dashboard_checkout_' . uniqid() . '@test.com';
    $user = createTestUser($client, $email);

    $client->loginUser($user);
    $client->request('GET', '/dashboard?checkout=success');

    $content = (string) $client->getResponse()->getContent();
    expect($client->getResponse()->getStatusCode())->toBe(200)
        ->and($content)->toContain('Briefly Premium');
});
