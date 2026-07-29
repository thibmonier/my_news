<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/*
 * Feature tests — GET /admin/articles
 *
 * Couvre les scénarios Gherkin US-020 :
 * - 401 sans authentification
 * - 200 + liste JSON paginée avec ROLE_ADMIN
 * - 200 page 2 (pagination)
 * - Structure JSON valide pour chaque article
 *
 * Tests passant sans base de données réelle :
 * - La liste d'articles peut être vide (count=0) — acceptable pour le Walking Skeleton
 * - Les assertions portent sur la structure JSON, pas le contenu
 */
uses(WebTestCase::class);

test('GET /admin/articles retourne 401 sans authentification', function (): void {
    $client = static::createClient();
    $client->request('GET', '/admin/articles');

    expect($client->getResponse()->getStatusCode())->toBe(401);
});

test('GET /admin/articles avec ROLE_ADMIN retourne 200 et une structure JSON valide', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');
    $client->request('GET', '/admin/articles');

    $response = $client->getResponse();
    expect($response->getStatusCode())->toBe(200);

    $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

    expect($data)->toBeArray()
        ->and($data)->toHaveKey('page')
        ->and($data)->toHaveKey('perPage')
        ->and($data)->toHaveKey('total')
        ->and($data)->toHaveKey('articles')
        ->and($data['page'])->toBe(1)
        ->and($data['perPage'])->toBe(50)
        ->and($data['articles'])->toBeArray();
});

test('GET /admin/articles?page=2 retourne la page 2 avec structure valide', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');
    $client->request('GET', '/admin/articles?page=2');

    $response = $client->getResponse();
    expect($response->getStatusCode())->toBe(200);

    $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

    expect($data['page'])->toBe(2)
        ->and($data['perPage'])->toBe(50);
});

test('GET /admin/articles Content-Type est application/json', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');
    $client->request('GET', '/admin/articles');

    expect($client->getResponse()->headers->get('Content-Type'))
        ->toContain('application/json');
});

test('GET /admin/articles chaque article expose les clés requises', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');
    $client->request('GET', '/admin/articles');

    $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

    // Si des articles existent (CI avec DB), vérifier la structure de chaque item
    foreach ($data['articles'] as $article) {
        expect($article)->toHaveKey('id')
            ->and($article)->toHaveKey('title')
            ->and($article)->toHaveKey('url')
            ->and($article)->toHaveKey('contentHash')
            ->and($article)->toHaveKey('publishedAt')
            ->and($article)->toHaveKey('sourceName');
    }
});
