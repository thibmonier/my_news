<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/*
 * Feature test — GET /login.
 *
 * Non-régression : le firewall main a `form_login.enable_csrf: true`, donc le
 * formulaire DOIT rendre le champ caché `_csrf_token`. Sans lui, tout POST /login
 * échoue silencieusement la validation CSRF et redirige vers /login (bug corrigé).
 * Les env de test désactivent souvent le CSRF, d'où l'absence de détection auparavant.
 */
uses(WebTestCase::class);

test('GET /login rend le champ CSRF _csrf_token attendu par form_login', function (): void {
    $client = static::createClient();
    $client->request('GET', '/login');

    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_OK);

    $html = (string) $client->getResponse()->getContent();
    expect($html)->toContain('name="_csrf_token"');
    expect($html)->toContain('name="email"');
    expect($html)->toContain('name="password"');
});
