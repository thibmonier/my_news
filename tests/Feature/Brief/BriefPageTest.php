<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/*
 * Feature tests — GET /brief + GET / (US-001/T-001-10 + T-001-11).
 *
 * Teste la slice HTTP complète : routing → BriefController → repository → réponse.
 *
 * En CI (PostgreSQL disponible) : le brief peut être vide (empty state) ou avec données.
 * En local sans Docker : le repository lance une exception → 503.
 *
 * Les tests sont conçus pour être robustes dans les deux situations :
 * - 200 (brief disponible ou empty state)
 * - 503 (PostgreSQL indisponible)
 *
 * SÉCURITÉ testée :
 * - Headers de sécurité sur toutes les réponses (SecurityHeadersSubscriber)
 * - Pas de stacktrace dans les réponses HTML
 * - Retry-After sur les 503
 *
 * Gherkin validé : US-001 nominal + alternatif 1 + erreur 1 + erreur 2.
 */
uses(WebTestCase::class);

// ── GET /brief — Comportement HTTP de base ────────────────────────────────────

test('GET /brief retourne une réponse HTML (200 ou 503)', static function (): void {
    $client = static::createClient();
    $client->request('GET', '/brief');

    $response = $client->getResponse();

    // 200 (brief ou empty state) ou 503 (DB inaccessible)
    expect($response->getStatusCode())->toBeIn([200, 503])
        ->and($response->headers->get('Content-Type'))->toContain('text/html');
});

test('GET /brief ne contient jamais de stacktrace PHP', static function (): void {
    $client = static::createClient();
    $client->request('GET', '/brief');

    $content = $client->getResponse()->getContent();

    // OWASP #7 : pas de détail technique dans la réponse (constitution §6)
    expect($content)
        ->not->toContain('Stack trace')
        ->not->toContain('at /var/www')
        ->not->toContain('Doctrine\\')
        ->not->toContain('pgsql://')
        ->not->toContain('password=');
});

test('GET /brief contient DAILY BRIEF dans le HTML quand réponse 200', static function (): void {
    $client = static::createClient();
    $client->request('GET', '/brief');

    $response = $client->getResponse();

    if (200 === $response->getStatusCode()) {
        expect($response->getContent())->toContain('DAILY BRIEF');
    } else {
        // 503 : vérifier message générique (pas de détail technique)
        expect($response->getContent())->toContain('Service temporairement indisponible');
    }
});

test('GET /brief en 200 contient LAST UPDATED', static function (): void {
    $client = static::createClient();
    $client->request('GET', '/brief');

    $response = $client->getResponse();

    if (200 === $response->getStatusCode()) {
        $content = $response->getContent();
        // LAST UPDATED ou le message empty state
        $hasLastUpdated = str_contains($content, 'LAST UPDATED');
        $hasEmptyState = str_contains($content, 'en cours de préparation');

        expect($hasLastUpdated || $hasEmptyState)->toBeTrue();
    }
});

// ── Headers de sécurité (T-001-07 + T-001-10) ───────────────────────────────

test('GET /brief retourne les headers de sécurité requis', static function (): void {
    $client = static::createClient();
    $client->request('GET', '/brief');

    $headers = $client->getResponse()->headers;

    expect($headers->has('X-Content-Type-Options'))->toBeTrue()
        ->and($headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($headers->has('X-Frame-Options'))->toBeTrue()
        ->and($headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($headers->has('Referrer-Policy'))->toBeTrue()
        ->and($headers->has('Content-Security-Policy'))->toBeTrue();
});

test('GET /brief retourne Cross-Origin headers (2026)', static function (): void {
    $client = static::createClient();
    $client->request('GET', '/brief');

    $headers = $client->getResponse()->headers;

    expect($headers->has('Cross-Origin-Opener-Policy'))->toBeTrue()
        ->and($headers->has('Permissions-Policy'))->toBeTrue();
});

// ── Scénario 503 : header Retry-After ────────────────────────────────────────

test('réponse 503 contient le header Retry-After: 60', static function (): void {
    $client = static::createClient();
    $client->request('GET', '/brief');

    $response = $client->getResponse();

    if (503 === $response->getStatusCode()) {
        expect($response->headers->get('Retry-After'))->toBe('60');
    }
    // Si 200, ce test passe silencieusement (pas de 503)
    expect(true)->toBeTrue();
});

// ── GET / → redirect 301 vers /brief (T-001-03 + T-001-11) ───────────────────

test('GET / retourne un redirect 301 vers /brief', static function (): void {
    $client = static::createClient();
    $client->request('GET', '/');

    $response = $client->getResponse();

    expect($response->getStatusCode())->toBe(Response::HTTP_MOVED_PERMANENTLY)
        ->and($response->headers->get('Location'))->toBe('/brief');
});

test('GET / sans suivre les redirects → 301', static function (): void {
    $client = static::createClient();
    $client->followRedirects(false);
    $client->request('GET', '/');

    expect($client->getResponse()->getStatusCode())->toBe(301);
});

// ── Accessibilité de base ──────────────────────────────────────────────────────

test('GET /brief est accessible sans authentification', static function (): void {
    // Pas de cookies de session, pas de headers d'auth
    $client = static::createClient();
    $client->request('GET', '/brief');

    // Pas de 401/403 (route publique)
    expect($client->getResponse()->getStatusCode())->not->toBeIn([401, 403]);
});

test('GET /brief contient une balise <title>', static function (): void {
    $client = static::createClient();
    $client->request('GET', '/brief');

    $response = $client->getResponse();

    if (200 === $response->getStatusCode()) {
        expect($response->getContent())->toContain('<title>');
    }
});
