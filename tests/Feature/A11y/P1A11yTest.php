<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/*
 * Tests de non-régression A11y P1 — WCAG 2.2 AA + INV-2.
 *
 * Fixes vérifiés :
 *   P1-1 : Contraste émeraude + INV-2 — boutons submit NON-IA n'utilisent plus #10B981
 *   P1-2 : Skip-link présent sur /brief, /login, /register
 *   P1-3 : <main id="main-content"> présent sur /login et /register (landmarks auth)
 *
 * Ces tests garantissent que les fixes accessibilité ne régressent pas.
 */

uses(WebTestCase::class);

// ── P1-2 : Skip-link ────────────────────────────────────────────────────────────

test('P1-2 A11y : skip-link "Aller au contenu principal" présent sur GET /login', function (): void {
    $client = static::createClient();
    $client->request('GET', '/login');

    $content = (string) $client->getResponse()->getContent();

    expect($client->getResponse()->getStatusCode())->toBe(200);
    expect($content)->toContain('href="#main-content"');
    expect($content)->toContain('Aller au contenu principal');
});

test('P1-2 A11y : skip-link "Aller au contenu principal" présent sur GET /register', function (): void {
    $client = static::createClient();
    $client->request('GET', '/register');

    $content = (string) $client->getResponse()->getContent();

    expect($client->getResponse()->getStatusCode())->toBe(200);
    expect($content)->toContain('href="#main-content"');
    expect($content)->toContain('Aller au contenu principal');
});

test('P1-2 A11y : skip-link "Aller au contenu principal" présent sur GET /brief (200 ou 503)', function (): void {
    $client = static::createClient();
    $client->request('GET', '/brief');

    $status = $client->getResponse()->getStatusCode();
    expect($status)->toBeIn([200, 503]);

    $content = (string) $client->getResponse()->getContent();
    expect($content)->toContain('href="#main-content"');
    expect($content)->toContain('Aller au contenu principal');
});

// ── P1-3 : Landmarks auth ───────────────────────────────────────────────────────

test('P1-3 A11y : <main id="main-content"> présent sur GET /login', function (): void {
    $client = static::createClient();
    $client->request('GET', '/login');

    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('id="main-content"');
    // Vérifie que c'est bien une balise <main>, pas un autre élément
    expect($content)->toMatch('/<main[^>]+id="main-content"/');
});

test('P1-3 A11y : <main id="main-content"> présent sur GET /register', function (): void {
    $client = static::createClient();
    $client->request('GET', '/register');

    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('id="main-content"');
    expect($content)->toMatch('/<main[^>]+id="main-content"/');
});

// ── P1-1 : Boutons submit NON-IA sans émeraude (INV-2) ─────────────────────────

test('P1-1 INV-2 : bouton submit de /login n\'utilise pas la couleur émeraude #10B981 en fond', function (): void {
    $client = static::createClient();
    $client->request('GET', '/login');

    $content = (string) $client->getResponse()->getContent();

    // Le bouton submit ne doit pas avoir background:#10B981 (couleur réservée à l'IA — INV-2)
    // On vérifie que la ligne CSS du bouton submit utilise la couleur primaire
    expect($content)->toContain('button[type="submit"]');
    // background:#10B981 ne doit pas apparaître dans le bloc CSS du bouton submit
    expect($content)->not->toMatch('/button\[type="submit"\][^}]*background:\s*#10B981/');
});

test('P1-1 INV-2 : bouton submit de /register n\'utilise pas la couleur émeraude #10B981 en fond', function (): void {
    $client = static::createClient();
    $client->request('GET', '/register');

    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('button[type="submit"]');
    expect($content)->not->toMatch('/button\[type="submit"\][^}]*background:\s*#10B981/');
});
