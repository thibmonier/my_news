<?php

declare(strict_types=1);

use App\Application\Summary\ArticleSummaryServiceInterface;
use App\Domain\Brief\BriefPublicView;
use App\Domain\Brief\BriefPublicViewRepositoryInterface;
use App\Domain\Brief\BriefStoryPublicView;
use App\Domain\Summary\ArticleSummary;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/*
 * Feature tests — BriefController couverture complète (migration US-001/T-001-08).
 *
 * Ce fichier remplace tests/Unit/Presentation/Controller/BriefControllerTest.php
 * qui instanciait le contrôleur sans container (incompatible avec extends AbstractController
 * + $this->render() Twig). Voir ADR migration Twig.
 *
 * Comportements couverts (non couverts par BriefPageTest/BriefSummaryTest/FeaturedSummaryBriefTest) :
 * - Histoires numérotées 01/02/03
 * - Contenu HTML (titres, sources, extraits avec &#039; — encodage Twig)
 * - Meta tags SEO (description, og:title, og:url)
 * - LAST UPDATED + UTC dans le timestamp
 * - Empty state explicite (200 + "en cours de préparation")
 * - 503 explicite (Retry-After: 60, pas de stacktrace, message générique)
 * - Redirect 301 GET / → /brief
 *
 * Stratégie : mock BriefPublicViewRepositoryInterface + ArticleSummaryServiceInterface
 * via static::getContainer()->set(...) (kernel.test = true, services publics).
 * Apostrophe : Twig encode ' en &#039; (ENT_QUOTES sans ENT_HTML5), pas &apos;.
 */

uses(WebTestCase::class);

// ── Factories internes ────────────────────────────────────────────────────────

function cov001BriefRepository(): BriefPublicViewRepositoryInterface
{
    return new class implements BriefPublicViewRepositoryInterface {
        public function findLatestPublicView(): ?BriefPublicView
        {
            return new BriefPublicView(
                updatedAt: new DateTimeImmutable('2026-07-28T14:30:00Z'),
                stories: [
                    new BriefStoryPublicView(
                        position: 1,
                        articleTitle: 'Titre article 1',
                        articleUrl: 'https://example.com/article-1',
                        excerpt: "Extrait de l'article 1 (moins de 280 caractères pour ce test unitaire).",
                        sourceName: 'Source Tech 1',
                        articleId: '',
                    ),
                    new BriefStoryPublicView(
                        position: 2,
                        articleTitle: 'Titre article 2',
                        articleUrl: 'https://example.com/article-2',
                        excerpt: 'Extrait de l\'article 2.',
                        sourceName: 'Source Tech 2',
                        articleId: '',
                    ),
                    new BriefStoryPublicView(
                        position: 3,
                        articleTitle: 'Titre article 3',
                        articleUrl: 'https://example.com/article-3',
                        excerpt: 'Extrait de l\'article 3.',
                        sourceName: 'Source Tech 3',
                        articleId: '',
                    ),
                ],
            );
        }
    };
}

function cov001EmptyBriefRepository(): BriefPublicViewRepositoryInterface
{
    return new class implements BriefPublicViewRepositoryInterface {
        public function findLatestPublicView(): ?BriefPublicView
        {
            return null;
        }
    };
}

function cov001ThrowingBriefRepository(): BriefPublicViewRepositoryInterface
{
    return new class implements BriefPublicViewRepositoryInterface {
        public function findLatestPublicView(): ?BriefPublicView
        {
            throw new RuntimeException('SQLSTATE[08006]: Connection refused');
        }
    };
}

function cov001NoSummaryService(): ArticleSummaryServiceInterface
{
    return new class implements ArticleSummaryServiceInterface {
        public function getSummary(string $articleId, string $articleText): ArticleSummary
        {
            throw new RuntimeException('No summary service available');
        }
    };
}

// ── Scénario nominal — Contenu HTML ──────────────────────────────────────────

test('cov001 : GET /brief retourne 200 avec HTML quand brief disponible', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001BriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $response = $client->getResponse();

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($response->headers->get('Content-Type'))->toContain('text/html');
});

test('cov001 : GET /brief contient DAILY BRIEF dans le titre h1', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001BriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('DAILY BRIEF');
});

test('cov001 : GET /brief affiche LAST UPDATED avec la date UTC', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001BriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)
        ->toContain('LAST UPDATED')
        ->toContain('UTC');
});

test('cov001 : GET /brief affiche les 3 histoires numérotées 01, 02, 03', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001BriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)
        ->toContain('01')
        ->toContain('02')
        ->toContain('03');
});

test('cov001 : GET /brief affiche les titres et sources des histoires', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001BriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)
        ->toContain('Titre article 1')
        ->toContain('Source Tech 1');
});

test('cov001 : GET /brief échappe l\'apostrophe en &#039; (encodage Twig — pas &apos;)', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001BriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // Twig encode ' en &#039; (ENT_QUOTES sans ENT_HTML5).
    // L'ancienne implémentation PHP utilisait ENT_HTML5 → &apos; ; Twig → &#039;.
    expect($content)->toContain('Extrait de l&#039;article 1');
});

test('cov001 : GET /brief contient rel="noopener noreferrer" et OUVRIR L\'ORIGINAL', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001BriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)
        ->toContain('OUVRIR L\'ORIGINAL')
        ->toContain('rel="noopener noreferrer"');
});

// ── Meta tags SEO ─────────────────────────────────────────────────────────────

test('cov001 : GET /brief contient les meta tags SEO (description, og:title, og:url)', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001BriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)
        ->toContain('<title>')
        ->toContain('name="description"')
        ->toContain('property="og:title"')
        ->toContain('property="og:url"');
});

// ── Scénario erreur 1 — Empty state ──────────────────────────────────────────

test('cov001 : GET /brief retourne 200 avec empty state quand aucun brief (table vide)', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001EmptyBriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $response = $client->getResponse();

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and((string) $response->getContent())->toContain('en cours de préparation')
        ->and((string) $response->getContent())->not->toContain('Fatal error')
        ->and((string) $response->getContent())->not->toContain('Exception');
});

test('cov001 : empty state ne contient pas de stacktrace PHP', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001EmptyBriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)
        ->not->toContain('Stack trace')
        ->not->toContain('Throwable')
        ->not->toContain('#0 ')
        ->not->toContain('Doctrine\\');
});

// ── Scénario erreur 2 — DB indisponible (503) ────────────────────────────────

test('cov001 : GET /brief retourne 503 quand la DB est indisponible', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001ThrowingBriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $response = $client->getResponse();

    expect($response->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
});

test('cov001 : réponse 503 contient le header Retry-After: 60', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001ThrowingBriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $response = $client->getResponse();

    expect($response->headers->get('Retry-After'))->toBe('60');
});

test('cov001 : réponse 503 ne contient pas de détail technique (OWASP #7)', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, cov001ThrowingBriefRepository());
    $container->set(ArticleSummaryServiceInterface::class, cov001NoSummaryService());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // OWASP #7 : pas de détail technique dans la réponse
    expect($content)
        ->toContain('Service temporairement indisponible')
        ->not->toContain('SQLSTATE')
        ->not->toContain('Stack trace')
        ->not->toContain('Exception');
});

// ── Redirect GET / → /brief ──────────────────────────────────────────────────

test('cov001 : GET / retourne un redirect 301 vers /brief', function (): void {
    $client = static::createClient();
    $client->followRedirects(false);
    $client->request('GET', '/');

    $response = $client->getResponse();

    expect($response->getStatusCode())->toBe(Response::HTTP_MOVED_PERMANENTLY)
        ->and($response->headers->get('Location'))->toBe('/brief');
});
