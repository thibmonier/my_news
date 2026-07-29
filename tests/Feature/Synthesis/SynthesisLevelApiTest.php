<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/*
 * Feature tests — POST /api/v1/synthesis + level param (US-011) — niveaux de synthèse
 *
 * L'endpoint /api/v1/synthesis requiert ROLE_USER (firewall main, security.yaml §70).
 * Sans authentification → 401 avant même que la validation du DTO ne s'exécute.
 *
 * Couvre T-011-12 :
 *   - Routes enregistrées pour les 3 niveaux (non 404)
 *   - Réponse non-authentifiée attendue (401/403) — auth requise selon security.yaml
 *   - Absence de stacktrace dans les réponses (OWASP A05)
 *   - Validation DTO 422 couverte en unitaire (SynthesisLevelTest + UrlSynthesisProcessorTest)
 *
 * Note : les appels Mistral réels et la validation post-auth (422 level invalide)
 * sont couverts par les tests unitaires du service et du processeur.
 */
uses(WebTestCase::class);

beforeEach(function (): void {
    static::ensureKernelShutdown();
});

afterEach(function (): void {
    static::ensureKernelShutdown();
});

// ── Routes enregistrées (non 404) ─────────────────────────────────────────────

test('POST /api/v1/synthesis avec level="concise" est une route enregistrée (non 404)', function (): void {
    $client = static::createClient();
    $client->request(
        'POST',
        '/api/v1/synthesis',
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"url":"https://example.com/article","level":"concise"}',
    );

    expect($client->getResponse()->getStatusCode())->not->toBe(404);
});

test('POST /api/v1/synthesis avec level="detailed" est une route enregistrée (non 404)', function (): void {
    $client = static::createClient();
    $client->request(
        'POST',
        '/api/v1/synthesis',
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"url":"https://example.com/article","level":"detailed"}',
    );

    expect($client->getResponse()->getStatusCode())->not->toBe(404);
});

test('POST /api/v1/synthesis avec level="narrative" est une route enregistrée (non 404)', function (): void {
    $client = static::createClient();
    $client->request(
        'POST',
        '/api/v1/synthesis',
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"url":"https://example.com/article","level":"narrative"}',
    );

    expect($client->getResponse()->getStatusCode())->not->toBe(404);
});

test('POST /api/v1/synthesis avec level="ultra" est une route enregistrée (non 404)', function (): void {
    $client = static::createClient();
    $client->request(
        'POST',
        '/api/v1/synthesis',
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"url":"https://example.com/article","level":"ultra"}',
    );

    expect($client->getResponse()->getStatusCode())->not->toBe(404);
});

test('POST /api/v1/synthesis sans level est une route enregistrée (non 404, défaut concise)', function (): void {
    $client = static::createClient();
    $client->request(
        'POST',
        '/api/v1/synthesis',
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"url":"https://example.com/article"}',
    );

    expect($client->getResponse()->getStatusCode())->not->toBe(404);
});

// ── Authentification requise pour tous les niveaux ───────────────────────────

test('POST /api/v1/synthesis avec level="concise" sans auth retourne 401 ou 403', function (): void {
    $client = static::createClient();
    $client->request(
        'POST',
        '/api/v1/synthesis',
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"url":"https://example.com/article","level":"concise"}',
    );

    expect($client->getResponse()->getStatusCode())->toBeIn([401, 403]);
});

// ── Absence de stacktrace dans les réponses (OWASP A05) ──────────────────────

test('réponse sans auth ne contient pas de stacktrace PHP (OWASP A05)', function (): void {
    $client = static::createClient();
    $client->request(
        'POST',
        '/api/v1/synthesis',
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"url":"https://example.com/article","level":"detailed"}',
    );

    $content = (string) $client->getResponse()->getContent();

    expect($content)->not->toContain('Stack trace')
        ->and($content)->not->toContain('#0 ');
});

// ── Content-Type JSON sur les réponses d'erreur ───────────────────────────────

test('réponse 401 sur synthesis avec level retourne du JSON', function (): void {
    $client = static::createClient();
    $client->request(
        'POST',
        '/api/v1/synthesis',
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"url":"https://example.com/article","level":"narrative"}',
    );

    $status = $client->getResponse()->getStatusCode();
    $contentType = (string) $client->getResponse()->headers->get('Content-Type');

    if (in_array($status, [401, 403], true)) {
        expect($contentType)->toContain('json');
    }
});
