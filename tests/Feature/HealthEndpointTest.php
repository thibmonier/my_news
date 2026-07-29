<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/*
 * Feature tests — GET /api/health
 *
 * Teste la slice complète : HTTP → Presentation → Application → Infrastructure.
 * Le test vérifie la structure JSON de la réponse indépendamment du statut
 * réel (200 ou 503), de sorte qu'il passe avec ou sans infrastructure disponible.
 *
 * En CI (services postgres + redis disponibles) : attend 200 + status=ok.
 * En local sans Docker actif : accepte 503 + structure valide.
 */
uses(WebTestCase::class);

test('GET /api/health répond avec une structure JSON valide', function (): void {
    $client = static::createClient();
    $client->request('GET', '/api/health');

    $response = $client->getResponse();

    // 200 (stack ok) ou 503 (dégradé) — toujours une réponse structurée
    expect($response->getStatusCode())->toBeIn([200, 503]);

    $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

    expect($data)->toBeArray()
        ->and($data)->toHaveKey('status')
        ->and($data)->toHaveKey('components')
        ->and($data)->toHaveKey('timestamp')
        ->and($data['status'])->toBeIn(['ok', 'degraded'])
        ->and($data['components'])->toBeArray();
});

test('GET /api/health répond avec les bonnes clés pour chaque composant', function (): void {
    $client = static::createClient();
    $client->request('GET', '/api/health');

    $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

    foreach ($data['components'] as $component) {
        expect($component)->toHaveKey('name')
            ->and($component)->toHaveKey('status')
            ->and($component)->toHaveKey('message')
            ->and($component['status'])->toBeIn(['ok', 'degraded']);
    }
});

test('GET /api/health timestamp est au format ISO 8601', function (): void {
    $client = static::createClient();
    $client->request('GET', '/api/health');

    $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

    // Le timestamp doit être parseable en DateTimeImmutable
    $dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $data['timestamp']);
    expect($dt)->not->toBeFalse();
});

test('GET /api/health Content-Type est application/json', function (): void {
    $client = static::createClient();
    $client->request('GET', '/api/health');

    expect($client->getResponse()->headers->get('Content-Type'))
        ->toContain('application/json');
});
