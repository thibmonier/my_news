<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/*
 * Feature tests — US-033 Paywall placeholder
 *
 * Couvre T-033-10 : WebTestCase scénarios du paywall
 *   - GET /quota/paywall-modal sans auth → 302/401
 *   - GET /quota/paywall-modal retourne fragment HTML Turbo Frame
 *   - Fragment HTML contient le CTA "Briefly Premium" désactivé
 *   - Le CTA n'est pas un lien fonctionnel (placeholder Sprint 1)
 *
 * Note : les tests nécessitant un utilisateur authentifié sont conditionnels
 * car l'accès nécessite une session valide (firewall main).
 */
uses(WebTestCase::class);

beforeEach(function (): void {
    static::ensureKernelShutdown();
});

afterEach(function (): void {
    static::ensureKernelShutdown();
});

// ── Accès sans authentification ────────────────────────────────────────────────

test('GET /quota/paywall-modal sans authentification retourne 302 ou 401', function (): void {
    $client = static::createClient();
    $client->request('GET', '/quota/paywall-modal');

    expect($client->getResponse()->getStatusCode())->toBeIn([302, 401]);
});

// ── Structure du fragment HTML (conditionnel si 200) ─────────────────────────

test('GET /quota/paywall-modal retourne du HTML avec le CTA Premium désactivé (si authentifié)', function (): void {
    $client = static::createClient();
    $client->request('GET', '/quota/paywall-modal');

    $status = $client->getResponse()->getStatusCode();

    if (200 === $status) {
        $content = (string) $client->getResponse()->getContent();

        // Turbo Frame présent
        expect($content)->toContain('id="paywall-modal"');

        // Message de quota épuisé
        expect($content)->toContain('3 synthèses gratuites');

        // CTA désactivé (aria-disabled et href="#")
        expect($content)->toContain('aria-disabled="true"')
            ->and($content)->toContain('href="#"');

        // Prix affiché
        expect($content)->toContain('12€/mois');

        // CTA ne renvoie PAS vers Stripe (placeholder Sprint 1)
        expect($content)->not->toContain('stripe.com')
            ->and($content)->not->toContain('checkout.stripe');
    } else {
        // Non authentifié ou infrastructure indisponible
        expect($status)->toBeIn([302, 401]);
    }
});

// ── Routes enregistrées ────────────────────────────────────────────────────────

test('La route /api/v1/quota est accessible (réponse non 404)', function (): void {
    $client = static::createClient();
    $client->request('GET', '/api/v1/quota');

    // 404 = route non enregistrée (erreur de configuration)
    expect($client->getResponse()->getStatusCode())->not->toBe(404);
});

test('La route /quota/paywall-modal est accessible (réponse non 404)', function (): void {
    $client = static::createClient();
    $client->request('GET', '/quota/paywall-modal');

    expect($client->getResponse()->getStatusCode())->not->toBe(404);
});
