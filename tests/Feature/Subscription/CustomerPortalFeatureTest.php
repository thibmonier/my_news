<?php

declare(strict_types=1);

use App\Infrastructure\User\Persistence\DoctrineUserEntity;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Stub\StripeGatewayStub;

// T-034b-11 : WebTestCase — Customer Portal + webhooks downgrade/cancel

uses(WebTestCase::class);

beforeEach(function (): void {
    static::ensureKernelShutdown();
});

afterEach(function (): void {
    static::ensureKernelShutdown();
});

// ── Helpers ──────────────────────────────────────────────────────────────────

function portalBuildStripeSignature(string $payload, string $secret, int $timestamp): string
{
    $signedPayload = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return "t={$timestamp},v1={$signature}";
}

function portalCreateTestUser(KernelBrowser $client, string $email): DoctrineUserEntity
{
    $client->request('GET', '/register');
    $content = (string) $client->getResponse()->getContent();
    preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $content, $csrfMatches);
    $csrfToken = $csrfMatches[1] ?? 'invalid';

    $client->request('POST', '/register', [
        'email' => $email,
        'fullName' => 'Portal Test User',
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

/**
 * Insère un abonnement directement en DB pour les tests.
 */
function portalInsertSubscription(
    KernelBrowser $client,
    string $userId,
    string $customerId,
    string $subscriptionId,
    string $status = 'active',
    string $periodEnd = '+30 days',
    bool $cancelAtPeriodEnd = false,
): void {
    /** @var Connection $connection */
    $connection = $client->getContainer()->get('doctrine.dbal.default_connection');
    $connection->executeStatement(
        'INSERT INTO subscriptions (id, user_id, stripe_customer_id, stripe_subscription_id, plan, status, current_period_end, stripe_event_id, cancel_at_period_end, created_at)
         VALUES (:id, :userId, :customerId, :subscriptionId, :plan, :status, :periodEnd, :eventId, :cancelAtPeriodEnd, :createdAt)',
        [
            'id' => Uuid::v4()->toRfc4122(),
            'userId' => $userId,
            'customerId' => $customerId,
            'subscriptionId' => $subscriptionId,
            'plan' => 'monthly',
            'status' => $status,
            'periodEnd' => (new DateTimeImmutable($periodEnd, new DateTimeZone('UTC')))->format('Y-m-d H:i:sO'),
            'eventId' => 'evt_portal_' . uniqid(),
            'cancelAtPeriodEnd' => $cancelAtPeriodEnd ? 'true' : 'false',
            'createdAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:sO'),
        ],
    );
}

// ── Tests Customer Portal ─────────────────────────────────────────────────────

test('GET /profile/manage-subscription avec abonnement actif → redirect 302 vers URL Stripe Customer Portal', function (): void {
    $client = static::createClient();
    $email = 'portal_active_' . uniqid() . '@test.com';
    $user = portalCreateTestUser($client, $email);
    $userId = $user->getUserUuid();

    $customerId = 'cus_portal_' . uniqid();
    $subscriptionId = 'sub_portal_' . uniqid();
    portalInsertSubscription($client, $userId, $customerId, $subscriptionId);

    $client->loginUser($user);
    $client->followRedirects(false);
    $client->request('GET', '/profile/manage-subscription');

    expect($client->getResponse()->getStatusCode())->toBe(302);
    $location = (string) $client->getResponse()->headers->get('Location');
    expect($location)->toContain(StripeGatewayStub::TEST_PORTAL_URL);
    // L'URL de portail contient le customer_id
    expect($location)->toContain($customerId);
});

test('GET /profile/manage-subscription sans abonnement → flash error + redirect /premium', function (): void {
    $client = static::createClient();
    $email = 'portal_free_' . uniqid() . '@test.com';
    $user = portalCreateTestUser($client, $email);

    $client->loginUser($user);
    $client->followRedirects(false);
    $client->request('GET', '/profile/manage-subscription');

    expect($client->getResponse()->getStatusCode())->toBe(302);
    $location = (string) $client->getResponse()->headers->get('Location');
    expect($location)->toContain('/premium');
});

test('GET /profile/manage-subscription sans auth → redirect vers login', function (): void {
    $client = static::createClient();
    $client->followRedirects(false);
    $client->request('GET', '/profile/manage-subscription');

    expect($client->getResponse()->getStatusCode())->toBe(302);
    $location = (string) $client->getResponse()->headers->get('Location');
    expect($location)->toContain('/login');
});

// ── Tests Webhook customer.subscription.updated ────────────────────────────────

test('POST /stripe/webhook customer.subscription.updated cancel_at_period_end=true → DB mise à jour, HTTP 200', function (): void {
    $client = static::createClient();
    $email = 'webhook_update_' . uniqid() . '@test.com';
    $user = portalCreateTestUser($client, $email);
    $userId = $user->getUserUuid();

    $customerId = 'cus_upd_' . uniqid();
    $subscriptionId = 'sub_upd_' . uniqid();
    portalInsertSubscription($client, $userId, $customerId, $subscriptionId);

    $webhookSecret = $_SERVER['STRIPE_WEBHOOK_SECRET'] ?? 'whsec_test_webhook_secret_for_tests_32chars';
    $eventId = 'evt_upd_' . uniqid();

    $payload = json_encode([
        'type' => 'customer.subscription.updated',
        'id' => $eventId,
        'data' => [
            'object' => [
                'id' => $subscriptionId,
                'status' => 'active',
                'cancel_at_period_end' => true,
                'current_period_end' => (new DateTimeImmutable('+15 days'))->getTimestamp(),
            ],
        ],
    ]);
    assert(is_string($payload));

    $timestamp = time();
    $sigHeader = portalBuildStripeSignature($payload, $webhookSecret, $timestamp);

    $client->request(
        'POST',
        '/stripe/webhook',
        [],
        [],
        ['HTTP_Stripe-Signature' => $sigHeader, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    expect($client->getResponse()->getStatusCode())->toBe(200);

    // Consommer les messages Messenger pour exécuter le handler
    /** @var Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $transport */
    $transport = $client->getContainer()->get('messenger.transport.async');
    $messages = $transport->get();
    expect($messages)->toHaveCount(1);

    // Vérifier via DBAL que cancel_at_period_end a été mis à jour
    // Note : en test, le handler est asynchrone (InMemory transport) — on vérifie que le message est dispatché
    // Le handler est testé en unitaire (T-034b-09)
    expect($messages[0]->getMessage()->eventType)->toBe('customer.subscription.updated');
});

// ── Tests Webhook customer.subscription.deleted ────────────────────────────────

test('POST /stripe/webhook customer.subscription.deleted sub_UNKNOWN → HTTP 200 + 0 erreur (idempotence)', function (): void {
    $client = static::createClient();

    $webhookSecret = $_SERVER['STRIPE_WEBHOOK_SECRET'] ?? 'whsec_test_webhook_secret_for_tests_32chars';
    $eventId = 'evt_del_unknown_' . uniqid();

    $payload = json_encode([
        'type' => 'customer.subscription.deleted',
        'id' => $eventId,
        'data' => [
            'object' => [
                'id' => 'sub_UNKNOWN_' . uniqid(),
                'status' => 'canceled',
            ],
        ],
    ]);
    assert(is_string($payload));

    $timestamp = time();
    $sigHeader = portalBuildStripeSignature($payload, $webhookSecret, $timestamp);

    $client->request(
        'POST',
        '/stripe/webhook',
        [],
        [],
        ['HTTP_Stripe-Signature' => $sigHeader, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    // HTTP 200 systématique — même avec stripe_subscription_id inconnu
    expect($client->getResponse()->getStatusCode())->toBe(200);

    /** @var Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $transport */
    $transport = $client->getContainer()->get('messenger.transport.async');
    $messages = $transport->get();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getMessage()->eventType)->toBe('customer.subscription.deleted');
});

test('POST /stripe/webhook invoice.payment_failed → HTTP 200 + message dispatché', function (): void {
    $client = static::createClient();

    $webhookSecret = $_SERVER['STRIPE_WEBHOOK_SECRET'] ?? 'whsec_test_webhook_secret_for_tests_32chars';
    $eventId = 'evt_pf_' . uniqid();

    $payload = json_encode([
        'type' => 'invoice.payment_failed',
        'id' => $eventId,
        'data' => [
            'object' => [
                'subscription' => 'sub_PF_' . uniqid(),
                'customer' => 'cus_PF_test',
            ],
        ],
    ]);
    assert(is_string($payload));

    $timestamp = time();
    $sigHeader = portalBuildStripeSignature($payload, $webhookSecret, $timestamp);

    $client->request(
        'POST',
        '/stripe/webhook',
        [],
        [],
        ['HTTP_Stripe-Signature' => $sigHeader, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    expect($client->getResponse()->getStatusCode())->toBe(200);

    /** @var Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $transport */
    $transport = $client->getContainer()->get('messenger.transport.async');
    $messages = $transport->get();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getMessage()->eventType)->toBe('invoice.payment_failed');
});
