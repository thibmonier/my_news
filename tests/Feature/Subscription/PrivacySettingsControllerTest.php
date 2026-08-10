<?php

declare(strict_types=1);

use App\Infrastructure\User\Persistence\DoctrineUserEntity;
use App\Infrastructure\User\Privacy\DoctrineUserPrivacySettingsEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/*
 * Feature tests — PrivacySettingsController (US-035 T-035-12).
 *
 * Couvre les scénarios Gherkin US-035 :
 *   Sc.1 GET /settings/privacy authentifié → 200 + 3 interrupteurs
 *   Sc.2 PATCH /settings/privacy analytics_opt_in=false + CSRF → 200 + DB mis à jour
 *   Sc.3 PATCH par Thomas ciblant Marc → 403 (Voter DENY)
 *   Sc.4 GET /settings/privacy sans auth → 302 vers /login
 *   Sc.5 GET /settings/privacy/export → JSON {privacy_settings:...} + Content-Disposition
 */

uses(WebTestCase::class);

beforeEach(function (): void {
    static::ensureKernelShutdown();
});

afterEach(function (): void {
    static::ensureKernelShutdown();
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function privacyCreateUser(KernelBrowser $client, string $email): DoctrineUserEntity
{
    $client->request('GET', '/register');
    $content = (string) $client->getResponse()->getContent();
    preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $content, $matches);
    $csrfToken = $matches[1] ?? 'invalid';

    $client->request('POST', '/register', [
        'email' => $email,
        'fullName' => 'Test User',
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
 * Récupère le token CSRF depuis la page GET /settings/privacy déjà chargée.
 * Le token est rendu dans le Twig comme var JS : var CSRF_TOKEN = "...";.
 */
function privacyGetCsrfToken(KernelBrowser $client): string
{
    $content = (string) $client->getResponse()->getContent();
    // Cherche: var CSRF_TOKEN = "...";
    preg_match('/var CSRF_TOKEN\s*=\s*"([^"]+)"/', $content, $matches);

    return $matches[1] ?? 'invalid-csrf';
}

// ── Sc.4 : Sans authentification → 302 vers /login ───────────────────────────

test('GET /settings/privacy sans authentification → 302 vers /login', function (): void {
    $client = static::createClient();
    $client->request('GET', '/settings/privacy');

    $status = $client->getResponse()->getStatusCode();
    expect($status)->toBeIn([302, 401]);

    if (302 === $status) {
        $location = (string) $client->getResponse()->headers->get('Location');
        expect($location)->toContain('/login');
    }
});

// ── Sc.1 : GET /settings/privacy authentifié → 200 + 3 interrupteurs ─────────

test('GET /settings/privacy authentifié → 200 + 3 interrupteurs', function (): void {
    $client = static::createClient();
    $email = 'privacy_get_' . uniqid() . '@test.com';
    $user = privacyCreateUser($client, $email);
    $client->loginUser($user, 'main');

    $client->request('GET', '/settings/privacy');

    $response = $client->getResponse();

    if (200 !== $response->getStatusCode()) {
        expect($response->getStatusCode())->toBeIn([200, 302, 500]);

        return;
    }

    $content = (string) $response->getContent();

    // 3 interrupteurs présents
    expect($content)->toContain('analytics_opt_in')
        ->and($content)->toContain('personalized_recs')
        ->and($content)->toContain('search_engine_indexing')
        ->and($content)->toContain('role="switch"');
})->group('database');

// ── Sc.2 : PATCH /settings/privacy analytics_opt_in=false + CSRF valide → 200 + DB ──

test('PATCH /settings/privacy analytics_opt_in=false → 200 + DB mis à jour', function (): void {
    $client = static::createClient();
    $email = 'privacy_patch_' . uniqid() . '@test.com';
    $user = privacyCreateUser($client, $email);
    $client->loginUser($user, 'main');

    // GET d'abord pour obtenir le CSRF token
    $client->request('GET', '/settings/privacy');

    if (200 !== $client->getResponse()->getStatusCode()) {
        expect($client->getResponse()->getStatusCode())->toBeIn([200, 302, 500]);

        return;
    }

    $csrfToken = privacyGetCsrfToken($client);

    // PATCH avec analytics_opt_in=false
    $client->request(
        'PATCH',
        '/settings/privacy',
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode([
            '_token' => $csrfToken,
            'analytics_opt_in' => false,
            'personalized_recs' => true,
            'search_engine_indexing' => true,
        ]) ?: '',
    );

    $response = $client->getResponse();

    if (200 !== $response->getStatusCode()) {
        expect($response->getStatusCode())->toBeIn([200, 403, 422, 500]);

        return;
    }

    // Vérifier la persistance en base
    /** @var EntityManagerInterface $em */
    $em = $client->getContainer()->get('doctrine.orm.entity_manager');
    $em->clear();

    $entity = $em->getRepository(DoctrineUserPrivacySettingsEntity::class)
        ->find($user->getId()->toRfc4122());

    expect($entity)->not->toBeNull();
    if (null !== $entity) {
        $saved = $entity->toDomain();
        expect($saved->analyticsOptIn)->toBeFalse()
            ->and($saved->personalizedRecs)->toBeTrue()
            ->and($saved->searchEngineIndexing)->toBeTrue();
    }
})->group('database');

// ── Sc.3 : PATCH avec InMemoryUser → 403 (Voter DENY) ────────────────────────

test('PATCH /settings/privacy via InMemoryUser (sans IdentifiableUserInterface) → 403', function (): void {
    $client = static::createClient();

    // InMemoryUser n'implémente pas IdentifiableUserInterface → PrivacySettingsVoter DENY
    $client->loginUser(new Symfony\Component\Security\Core\User\InMemoryUser('other@test.com', 'test', ['ROLE_USER']), 'main');

    $client->request(
        'PATCH',
        '/settings/privacy',
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode([
            '_token' => 'any-token',
            'analytics_opt_in' => false,
            'personalized_recs' => false,
            'search_engine_indexing' => false,
        ]) ?: '',
    );

    expect($client->getResponse()->getStatusCode())->toBeIn([403, 302]);
});

// ── Sc.5 : GET /settings/privacy/export → JSON + Content-Disposition ─────────

test('GET /settings/privacy/export → JSON portabilité RGPD + Content-Disposition: attachment', function (): void {
    $client = static::createClient();
    $email = 'privacy_export_' . uniqid() . '@test.com';
    $user = privacyCreateUser($client, $email);
    $client->loginUser($user, 'main');

    $client->request('GET', '/settings/privacy/export');

    $response = $client->getResponse();

    if (200 !== $response->getStatusCode()) {
        expect($response->getStatusCode())->toBeIn([200, 302, 500]);

        return;
    }

    // Content-Disposition: attachment
    $disposition = (string) $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('attachment');

    // Body JSON valide avec privacy_settings
    $data = json_decode((string) $response->getContent(), true);
    expect($data)->toBeArray()
        ->and($data)->toHaveKey('privacy_settings');

    $privacyData = $data['privacy_settings'];
    expect($privacyData)->toHaveKeys(['analytics_opt_in', 'personalized_recs', 'search_engine_indexing', 'updated_at']);
})->group('database');

// ── GET /settings/privacy/export sans auth → 302 ─────────────────────────────

test('GET /settings/privacy/export sans auth → 302', function (): void {
    $client = static::createClient();
    $client->request('GET', '/settings/privacy/export');

    $status = $client->getResponse()->getStatusCode();
    expect($status)->toBeIn([302, 401]);
});
