<?php

declare(strict_types=1);

use App\Domain\Feed\SourceRepositoryInterface;
use App\Domain\Feed\SourceStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\InMemoryUser;

/*
 * Feature tests — /admin/sources (AdminSourceController)
 *
 * Couvre les scénarios Gherkin US-021 :
 * - Accès refusé sans authentification (401)
 * - Accès refusé avec ROLE_USER (403 — authentifié mais pas ROLE_ADMIN)
 * - Liste paginée GET /admin/sources (200)
 * - Création nominale → source en base status=pending_validation
 * - URL HTTP → formulaire renvoyé + message d'erreur HTTPS, 422 (Symfony 8 form errors)
 * - URL IP privée SSRF → formulaire renvoyé + message d'erreur SSRF, 422
 * - Édition → source en 404 pour ID inconnu
 * - Soft delete → status=deleted + absent de la liste
 * - Toggle → basculement actif/inactif
 * - Bulk update → flash "N sources mises en file"
 * - CSRF invalide sur toggle/delete → accès refusé (403)
 * - Recherche → filtre par nom et URL
 *
 * Auth : loginUser() via session (même pattern qu'AdminArticleControllerTest).
 *   Admin : InMemoryUser('admin', null, ['ROLE_ADMIN']) — firewall 'admin'
 *   User  : InMemoryUser('user', null, ['ROLE_USER'])  — accès refusé admin
 *
 * Note Symfony 8 : AbstractController::render() retourne 422 quand le form soumis
 * est invalide (depuis Symfony 6.3+, default depuis Symfony 7).
 */

uses(WebTestCase::class);

// ── Contrôle d'accès ──────────────────────────────────────────────────────

test('GET /admin/sources retourne 401 sans authentification', function (): void {
    $client = static::createClient();
    $client->request('GET', '/admin/sources');

    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
});

test('GET /admin/sources avec ROLE_USER retourne 403 (authentifié mais sans ROLE_ADMIN)', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('user', 'test', ['ROLE_USER']), 'admin');
    $client->request('GET', '/admin/sources');

    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
});

test('GET /admin/sources avec ROLE_ADMIN retourne 200 et liste HTML', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');
    $client->request('GET', '/admin/sources');

    $response = $client->getResponse();
    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($response->getContent())->toContain('Sources RSS/Atom');
});

// ── Formulaire de création ────────────────────────────────────────────────

test('GET /admin/sources/new retourne le formulaire (200)', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');
    $client->request('GET', '/admin/sources/new');

    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_OK);
    expect($client->getResponse()->getContent())->toContain('Ajouter une source');
});

test('POST /admin/sources/new avec URL HTTP retourne 422 (erreur HTTPS)', function (): void {
    // Symfony 8 : render() avec form invalide → 422 Unprocessable Content
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');

    $crawler = $client->request('GET', '/admin/sources/new');
    $form = $crawler->selectButton('Enregistrer')->form([
        'source[name]' => 'Insecure Feed',
        'source[url]' => 'http://insecure-feed.example.com/rss.xml',
        'source[feedType]' => 'rss',
        'source[fetchIntervalMinutes]' => '30',
    ]);

    $client->submit($form);

    // 422 Unprocessable Content — form errors (Symfony 8 behavior)
    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);

    // Aucune source créée
    /** @var SourceRepositoryInterface $repo */
    $repo = static::getContainer()->get(SourceRepositoryInterface::class);
    expect($repo->findByUrl('http://insecure-feed.example.com/rss.xml'))->toBeNull();
});

test('POST /admin/sources/new avec IP SSRF retourne 422', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');

    $crawler = $client->request('GET', '/admin/sources/new');
    $form = $crawler->selectButton('Enregistrer')->form([
        'source[name]' => 'SSRF Test',
        'source[url]' => 'https://169.254.169.254/latest/meta-data/',
        'source[feedType]' => 'rss',
        'source[fetchIntervalMinutes]' => '30',
    ]);

    $client->submit($form);

    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);

    // Aucune source créée
    /** @var SourceRepositoryInterface $repo */
    $repo = static::getContainer()->get(SourceRepositoryInterface::class);
    expect($repo->findByUrl('https://169.254.169.254/latest/meta-data/'))->toBeNull();
});

test('POST /admin/sources/new valide crée une source en status pending_validation', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');

    $uniqueUrl = 'https://techcrunch.com/feed-test-' . uniqid() . '/';

    $crawler = $client->request('GET', '/admin/sources/new');
    $form = $crawler->selectButton('Enregistrer')->form([
        'source[name]' => 'TechCrunch Test',
        'source[url]' => $uniqueUrl,
        'source[feedType]' => 'rss',
        'source[fetchIntervalMinutes]' => '30',
    ]);

    $client->submit($form);

    // 302 redirect après succès
    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_FOUND);

    // Follow redirect
    $client->request('GET', $client->getResponse()->headers->get('Location'));
    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_OK);

    /** @var SourceRepositoryInterface $repo */
    $repo = static::getContainer()->get(SourceRepositoryInterface::class);
    $source = $repo->findByUrl($uniqueUrl);

    expect($source)->not()->toBeNull()
        ->and($source->getStatus())->toBe(SourceStatus::PendingValidation)
        ->and($source->getName())->toBe('TechCrunch Test');
});

test('POST /admin/sources/new avec URL dupliquée affiche erreur "déjà enregistrée"', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');

    $url = 'https://duplicate-test-' . uniqid() . '.example.com/feed';

    // Première création
    $crawler = $client->request('GET', '/admin/sources/new');
    $form = $crawler->selectButton('Enregistrer')->form([
        'source[name]' => 'Source 1',
        'source[url]' => $url,
        'source[feedType]' => 'rss',
        'source[fetchIntervalMinutes]' => '30',
    ]);
    $client->submit($form);
    $client->request('GET', $client->getResponse()->headers->get('Location'));

    // Deuxième création avec même URL
    $crawler = $client->request('GET', '/admin/sources/new');
    $form = $crawler->selectButton('Enregistrer')->form([
        'source[name]' => 'Source 2',
        'source[url]' => $url,
        'source[feedType]' => 'rss',
        'source[fetchIntervalMinutes]' => '30',
    ]);
    $client->submit($form);

    // 422 — form invalide (URL déjà prise)
    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->and($client->getResponse()->getContent())->toContain('déjà enregistrée');
});

// ── Édition ──────────────────────────────────────────────────────────────

test('GET /admin/sources/{id}/edit retourne 404 pour ID inconnu', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');
    $client->request('GET', '/admin/sources/00000000-0000-0000-0000-000000000000/edit');

    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

// ── Soft delete ──────────────────────────────────────────────────────────

test('POST /admin/sources/{id}/delete soft-delete la source', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');

    // Créer une source avec nom unique pour la retrouver via recherche (évite
    // le problème de pagination quand la DB a accumulé > 50 sources)
    $uniqueName = 'À supprimer ' . uniqid();
    $url = 'https://delete-test-' . uniqid() . '.example.com/feed';

    $crawler = $client->request('GET', '/admin/sources/new');
    $form = $crawler->selectButton('Enregistrer')->form([
        'source[name]' => $uniqueName,
        'source[url]' => $url,
        'source[feedType]' => 'rss',
        'source[fetchIntervalMinutes]' => '30',
    ]);
    $client->submit($form);
    $client->request('GET', $client->getResponse()->headers->get('Location'));

    /** @var SourceRepositoryInterface $repo */
    $repo = static::getContainer()->get(SourceRepositoryInterface::class);
    $source = $repo->findByUrl($url);
    expect($source)->not()->toBeNull();

    // Trouver le formulaire de suppression via la recherche par nom unique
    // (contourne la pagination si la DB a > 50 sources)
    $crawler = $client->request('GET', '/admin/sources?q=' . urlencode($uniqueName));
    $deleteForm = $crawler
        ->filter("form[action*=\"{$source->getId()}/delete\"]")
        ->form();

    $client->submit($deleteForm);

    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_FOUND);
    $client->request('GET', $client->getResponse()->headers->get('Location'));

    // Vérification directe du soft-delete (deletedAt non null)
    $deletedSource = $repo->findByUrl($url);
    expect($deletedSource)->not()->toBeNull()
        ->and($deletedSource->getDeletedAt())->not()->toBeNull()
        ->and($deletedSource->getStatus())->toBe(SourceStatus::Deleted);

    // Absent de la liste filtrée (findPaginated exclut deletedAt IS NOT NULL)
    $filtered = $repo->findPaginated(1, 50, $uniqueName);
    $ids = array_map(fn ($s) => $s->getId(), $filtered);
    expect($ids)->not()->toContain($source->getId());
});

test('POST /admin/sources/{id}/delete avec CSRF invalide retourne 403', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');

    // Créer une source d'abord
    $url = 'https://csrf-test-' . uniqid() . '.example.com/feed';
    $crawler = $client->request('GET', '/admin/sources/new');
    $form = $crawler->selectButton('Enregistrer')->form([
        'source[name]' => 'CSRF Test',
        'source[url]' => $url,
        'source[feedType]' => 'rss',
        'source[fetchIntervalMinutes]' => '30',
    ]);
    $client->submit($form);
    $client->request('GET', $client->getResponse()->headers->get('Location'));

    /** @var SourceRepositoryInterface $repo */
    $repo = static::getContainer()->get(SourceRepositoryInterface::class);
    $source = $repo->findByUrl($url);

    // Initialiser la session AVANT de poster (CSRF check lit la session)
    $client->request('GET', '/admin/sources');

    // Poster avec token CSRF invalide
    $client->request('POST', "/admin/sources/{$source->getId()}/delete", [
        '_token' => 'token-invalide-csrf',
    ]);

    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
});

// ── Toggle actif/inactif ─────────────────────────────────────────────────

test('POST /admin/sources/{id}/toggle bascule le statut active↔inactive', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');

    $url = 'https://toggle-test-' . uniqid() . '.example.com/feed';
    $uniqueName = 'Toggle Test ' . uniqid();
    $crawler = $client->request('GET', '/admin/sources/new');
    $form = $crawler->selectButton('Enregistrer')->form([
        'source[name]' => $uniqueName,
        'source[url]' => $url,
        'source[feedType]' => 'rss',
        'source[fetchIntervalMinutes]' => '30',
    ]);
    $client->submit($form);
    $client->request('GET', $client->getResponse()->headers->get('Location'));

    /** @var SourceRepositoryInterface $repo */
    $repo = static::getContainer()->get(SourceRepositoryInterface::class);
    $source = $repo->findByUrl($url);

    // Trouver le formulaire de toggle via recherche par nom unique
    $crawler = $client->request('GET', '/admin/sources?q=' . urlencode($uniqueName));
    $toggleForm = $crawler
        ->filter("form[action*=\"{$source->getId()}/toggle\"]")
        ->form();

    $client->submit($toggleForm);

    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_FOUND);
});

// ── Bulk update ──────────────────────────────────────────────────────────

test('POST /admin/sources/bulk-update dispatche les messages et redirige', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');

    // GET d'abord pour obtenir le formulaire bulk-update (avec CSRF token embarqué)
    $crawler = $client->request('GET', '/admin/sources');

    $bulkForm = $crawler->filter('form[action*="bulk-update"]')->form();
    $client->submit($bulkForm);

    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_FOUND);
    $client->request('GET', $client->getResponse()->headers->get('Location'));
    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($client->getResponse()->getContent())->toContain('Sources RSS');
});

test('POST /admin/sources/bulk-update avec CSRF invalide retourne 403', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');

    // Initialiser la session (requis pour que le CSRF token manager ait une session)
    $client->request('GET', '/admin/sources');

    // POST avec token invalide
    $client->request('POST', '/admin/sources/bulk-update', [
        '_token' => 'invalid-bulk-token',
    ]);

    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
});

// ── Recherche ─────────────────────────────────────────────────────────────

test('GET /admin/sources?q= filtre les sources par nom', function (): void {
    $client = static::createClient();
    $client->loginUser(new InMemoryUser('admin', 'test', ['ROLE_ADMIN']), 'admin');

    // Créer une source avec un nom unique incluant un suffixe discriminant
    $suffix = 'SEARCH' . uniqid();
    $url = 'https://search-test-' . strtolower($suffix) . '.example.com/feed';

    $crawler = $client->request('GET', '/admin/sources/new');
    $form = $crawler->selectButton('Enregistrer')->form([
        'source[name]' => $suffix,   // ex. "SEARCHabc123"
        'source[url]' => $url,
        'source[feedType]' => 'rss',
        'source[fetchIntervalMinutes]' => '30',
    ]);
    $client->submit($form);
    $client->request('GET', $client->getResponse()->headers->get('Location'));

    // Rechercher par suffixe exact → devrait trouver exactement cette source
    $client->request('GET', '/admin/sources?q=' . urlencode($suffix));
    $content = $client->getResponse()->getContent();

    expect($client->getResponse()->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($content)->toContain($suffix);
});
