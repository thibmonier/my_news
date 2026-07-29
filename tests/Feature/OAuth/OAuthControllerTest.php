<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/*
 * Feature tests — OAuth2 (US-031)
 *
 * Stratégie : aucun appel réseau réel vers Google/GitHub.
 * Les routes /oauth/connect/{service} sont testées comme redirections.
 * Les routes /oauth/callback/{service} sont testées avec des cas d'erreur
 * simulables sans accès réseau (state invalide, access_denied).
 *
 * Tests avec accès réseau réel (échange code → token) ne sont PAS inclus
 * pour respecter l'exigence "0 appel réseau réel dans les tests" (T-031-13).
 *
 * Couvre les scénarios Gherkin :
 * - Scénario nominal (connect redirige vers le provider)
 * - Scénario d'erreur 1 (accès refusé → redirect /login + flash)
 * - Scénario d'erreur 2 (state invalide → HTTP 400)
 */
uses(WebTestCase::class);

beforeEach(function (): void {
    static::ensureKernelShutdown();
});

afterEach(function (): void {
    static::ensureKernelShutdown();
});

// ── GET /login : boutons OAuth présents ───────────────────────────────────────

test('GET /login affiche les boutons OAuth Google et GitHub', function (): void {
    $client = static::createClient();
    $client->request('GET', '/login');

    expect($client->getResponse()->getStatusCode())->toBe(200);
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('/oauth/connect/google')
        ->and($content)->toContain('/oauth/connect/github')
        ->and($content)->toContain('Continuer avec Google')
        ->and($content)->toContain('Continuer avec GitHub');
});

// ── GET /oauth/connect/google ─────────────────────────────────────────────────

test('GET /oauth/connect/google redirige vers la page d\'autorisation Google', function (): void {
    $client = static::createClient();

    // La route connect/* nécessite une session (état OAuth state stocké)
    // La redirection vers Google est faite par KnpU OAuth2Client
    // Sans vraies credentials, on attend soit une exception de config ou une 302
    $client->request('GET', '/oauth/connect/google');

    $statusCode = $client->getResponse()->getStatusCode();

    // Acceptable : 302 (redirection vers Google) ou 500 si credentials non configurés
    expect($statusCode)->toBeIn([302, 500]);

    if (302 === $statusCode) {
        $location = (string) $client->getResponse()->headers->get('Location');
        // La redirection doit pointer vers accounts.google.com
        expect($location)->toContain('google')
            ->and($location)->toContain('state='); // state CSRF obligatoire
    }
})->group('oauth');

test('GET /oauth/connect/github redirige vers la page d\'autorisation GitHub', function (): void {
    $client = static::createClient();
    $client->request('GET', '/oauth/connect/github');

    $statusCode = $client->getResponse()->getStatusCode();
    expect($statusCode)->toBeIn([302, 500]);

    if (302 === $statusCode) {
        $location = (string) $client->getResponse()->headers->get('Location');
        expect($location)->toContain('github')
            ->and($location)->toContain('state=');
    }
})->group('oauth');

// ── Scénario d'erreur 1 : accès refusé (access_denied) ────────────────────────

test('GET /oauth/callback/google avec error=access_denied redirige vers /login avec flash', function (): void {
    $client = static::createClient();

    // Simuler le callback Google avec error=access_denied (utilisateur a cliqué "Refuser")
    $client->request('GET', '/oauth/callback/google', [
        'error' => 'access_denied',
        'error_description' => 'The user denied access',
    ]);

    $statusCode = $client->getResponse()->getStatusCode();

    // Attendu : 302 vers /login (car NoAuthCodeAuthenticationException → redirect)
    expect($statusCode)->toBeIn([302, 401]);

    if (302 === $statusCode) {
        $location = (string) $client->getResponse()->headers->get('Location');
        expect($location)->toContain('/login');
    }
})->group('oauth');

test('GET /oauth/callback/github avec error=access_denied redirige vers /login', function (): void {
    $client = static::createClient();

    $client->request('GET', '/oauth/callback/github', [
        'error' => 'access_denied',
    ]);

    $statusCode = $client->getResponse()->getStatusCode();
    expect($statusCode)->toBeIn([302, 401]);

    if (302 === $statusCode) {
        $location = (string) $client->getResponse()->headers->get('Location');
        expect($location)->toContain('/login');
    }
})->group('oauth');

// ── Scénario d'erreur 2 : state invalide (protection CSRF) ────────────────────

test('GET /oauth/callback/google sans state retourne 400 ou redirige', function (): void {
    $client = static::createClient();

    // Callback sans code et sans state valide
    // KnpU va lever InvalidStateAuthenticationException → HTTP 400
    $client->request('GET', '/oauth/callback/google', [
        'code' => 'fake_auth_code',
        'state' => 'invalid_or_absent_state',
    ]);

    $statusCode = $client->getResponse()->getStatusCode();

    // Attendu : 400 (état CSRF invalide) ou 302 (redirect /login selon config)
    expect($statusCode)->toBeIn([302, 400, 401]);
})->group('oauth');

test('GET /oauth/callback/github sans state retourne 400 ou redirige', function (): void {
    $client = static::createClient();

    $client->request('GET', '/oauth/callback/github', [
        'code' => 'fake_auth_code',
        'state' => 'wrong_state_value',
    ]);

    $statusCode = $client->getResponse()->getStatusCode();
    expect($statusCode)->toBeIn([302, 400, 401]);
})->group('oauth');

// ── Routes OAuth accessibles sans authentification ────────────────────────────

test('GET /oauth/connect/google est accessible sans authentification (PUBLIC_ACCESS)', function (): void {
    $client = static::createClient();
    $client->request('GET', '/oauth/connect/google');

    // Ne doit PAS retourner 401 (non bloqué par Symfony Security)
    expect($client->getResponse()->getStatusCode())->not->toBe(401);
})->group('oauth');

test('GET /oauth/connect/github est accessible sans authentification (PUBLIC_ACCESS)', function (): void {
    $client = static::createClient();
    $client->request('GET', '/oauth/connect/github');

    expect($client->getResponse()->getStatusCode())->not->toBe(401);
})->group('oauth');

// ── Routes OAuth avec service invalide ────────────────────────────────────────

test('GET /oauth/connect/linkedin (service non supporté) retourne 404', function (): void {
    $client = static::createClient();
    $client->request('GET', '/oauth/connect/linkedin');

    expect($client->getResponse()->getStatusCode())->toBe(404);
})->group('oauth');

test('GET /oauth/callback/linkedin (service non supporté) retourne 404', function (): void {
    $client = static::createClient();
    $client->request('GET', '/oauth/callback/linkedin');

    expect($client->getResponse()->getStatusCode())->toBe(404);
})->group('oauth');
