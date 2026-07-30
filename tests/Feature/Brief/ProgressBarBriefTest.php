<?php

declare(strict_types=1);

use App\Application\Brief\FeaturedSummary\FeaturedSummaryServiceInterface;
use App\Application\Summary\ArticleSummaryServiceInterface;
use App\Domain\Brief\BriefPublicView;
use App\Domain\Brief\BriefPublicViewRepositoryInterface;
use App\Domain\Brief\BriefStoryPublicView;
use App\Domain\Brief\FeaturedSummaryDTO;
use App\Domain\Summary\ArticleSummary;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/*
 * Feature tests — Barre de progression de lecture (US-007) sur GET /brief.
 *
 * Périmètre : 100% frontend SSR — aucun backend impliqué.
 * Tests couverts (Gherkin US-007) :
 * - Scénario erreur 1 : role="progressbar" présent dans le HTML source (sans JS)
 * - ARIA : aria-valuemin="0" et aria-valuemax="100" statiques dans le source
 * - ARIA : aria-valuenow="0" présent (valeur initiale)
 * - data-controller="progress-bar" présent (hook Stimulus pour Sprint 2+)
 * - CSS : position fixed, top 0, height 2px, z-index 100, transition, couleur émeraude
 * - Non-régression : rendu existant US-004/US-005/US-006/US-011 intact
 *
 * Note : la logique scroll (50%, 100%, throttle rAF, reset turbo:load, division par zéro)
 * est implémentée en JS vanilla et documentée dans progressBarJs(). Elle est testée
 * manuellement (pas d'infra de test JS dans la stack — YAGNI).
 *
 * Nommage préfixé `pb007` pour éviter les collisions globales Pest.
 */

uses(WebTestCase::class);

// ── Stubs ─────────────────────────────────────────────────────────────────────

function pb007BriefRepositoryStub(): BriefPublicViewRepositoryInterface
{
    return new class implements BriefPublicViewRepositoryInterface {
        public function findLatestPublicView(): ?BriefPublicView
        {
            return new BriefPublicView(
                updatedAt: new DateTimeImmutable('2026-07-30T05:00:00Z'),
                stories: [
                    new BriefStoryPublicView(
                        position: 1,
                        articleTitle: 'AI révolutionne le développement logiciel',
                        articleUrl: 'https://example.com/article-1',
                        excerpt: 'Les LLM changent les pratiques de développement.',
                        sourceName: 'Tech Crunch',
                        articleId: 'a1b2c3d4-e5f6-4789-abcd-ef0123456789',
                        rawContent: 'Les LLM changent les pratiques de développement logiciel en entreprise.',
                    ),
                    new BriefStoryPublicView(
                        position: 2,
                        articleTitle: 'Open Source : nouvelles licences en 2026',
                        articleUrl: 'https://example.com/article-2',
                        excerpt: "Les licences open source évoluent face à l'IA.",
                        sourceName: 'The Register',
                        articleId: 'b2c3d4e5-f6a7-4890-bcde-f01234567890',
                        rawContent: "Les licences open source évoluent face à l'IA et au cloud.",
                    ),
                    new BriefStoryPublicView(
                        position: 3,
                        articleTitle: 'Sécurité APIs : nouvelles menaces OWASP 2026',
                        articleUrl: 'https://example.com/article-3',
                        excerpt: 'Le rapport OWASP 2026 identifie de nouveaux vecteurs.',
                        sourceName: 'InfoQ',
                        articleId: 'c3d4e5f6-a7b8-4901-cdef-012345678901',
                        rawContent: "Le rapport OWASP 2026 identifie de nouveaux vecteurs d'attaque sur les APIs.",
                    ),
                ],
            );
        }
    };
}

function pb007SummaryServiceStub(): ArticleSummaryServiceInterface
{
    return new class implements ArticleSummaryServiceInterface {
        public function getSummary(string $articleId, string $articleText): ArticleSummary
        {
            return new ArticleSummary(
                articleId: $articleId,
                keyPoints: ['Point A.', 'Point B.', 'Point C.'],
                modelVersion: 'mistral-small-latest',
                createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        }
    };
}

function pb007FeaturedServiceStubNull(): FeaturedSummaryServiceInterface
{
    return new class implements FeaturedSummaryServiceInterface {
        public function generateForBrief(string $briefId, DateTimeImmutable $date, array $stories): FeaturedSummaryDTO
        {
            return new FeaturedSummaryDTO(
                briefId: $briefId,
                content: 'Synthèse générée.',
                modelVersion: 'mistral-small-latest',
                generatedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
                isFallback: false,
            );
        }

        public function getForToday(DateTimeImmutable $now): ?FeaturedSummaryDTO
        {
            return null;
        }
    };
}

// ── Scénario erreur 1 (sans JS) : ARIA présent dans le HTML source ───────────

test('US-007 : role="progressbar" présent dans le HTML source de GET /brief', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, pb007BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, pb007SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, pb007FeaturedServiceStubNull());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($client->getResponse()->getStatusCode())->toBe(200);
    // Gherkin US-007 scénario erreur 1 : role="progressbar" dans le HTML source (SSR)
    expect($content)->toContain('role="progressbar"');
});

test('US-007 ARIA : aria-valuemin="0" présent dans le HTML source', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, pb007BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, pb007SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, pb007FeaturedServiceStubNull());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('aria-valuemin="0"');
});

test('US-007 ARIA : aria-valuemax="100" présent dans le HTML source', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, pb007BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, pb007SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, pb007FeaturedServiceStubNull());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('aria-valuemax="100"');
});

test('US-007 ARIA : aria-valuenow="0" présent comme valeur initiale', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, pb007BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, pb007SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, pb007FeaturedServiceStubNull());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('aria-valuenow="0"');
});

// ── Stimulus hook ─────────────────────────────────────────────────────────────

test('US-007 : data-controller="progress-bar" présent dans le HTML source', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, pb007BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, pb007SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, pb007FeaturedServiceStubNull());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // Hook Stimulus Bridge (auto-découverte Assets controllers/ en Sprint 2+)
    expect($content)->toContain('data-controller="progress-bar"');
});

// ── CSS : structure de la barre ───────────────────────────────────────────────

test('US-007 CSS : .progress-bar avec position fixed présent dans le HTML', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, pb007BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, pb007SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, pb007FeaturedServiceStubNull());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('.progress-bar');
    expect($content)->toContain('position: fixed');
});

test('US-007 CSS : height 2px et z-index 100 présents', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, pb007BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, pb007SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, pb007FeaturedServiceStubNull());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('height: 2px');
    expect($content)->toContain('z-index: 100');
});

test('US-007 CSS : transition width 0.1s linear présente', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, pb007BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, pb007SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, pb007FeaturedServiceStubNull());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('transition: width 0.1s linear');
});

test('US-007 CSS : couleur émeraude #10B981 présente (INV-2)', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, pb007BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, pb007SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, pb007FeaturedServiceStubNull());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // Token CSS ou valeur littérale fallback (US-007 conversation §4 + T-007-02)
    expect($content)->toContain('#10B981');
});

// ── Non-régression : rendu existant (US-004, US-005, US-006, US-011) ─────────

test('US-007 non-régression : la barre ne casse pas le rendu existant DAILY BRIEF', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, pb007BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, pb007SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, pb007FeaturedServiceStubNull());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // US-001 : titre présent
    expect($content)->toContain('DAILY BRIEF');
    // US-004 : badge IA présent
    expect($content)->toContain('BRIEFLY AI:');
    // US-005 : badge catégorie présent
    expect($content)->toContain('class="badge');
    // US-011 : sélecteur de niveau présent
    expect($content)->toContain('synthesis-level-selector');
    // US-007 : barre de progression présente — sans casser les éléments ci-dessus
    expect($content)->toContain('role="progressbar"');
});

test('US-007 non-régression : ol#brief-stories présent (ancre US-006)', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, pb007BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, pb007SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, pb007FeaturedServiceStubNull());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // US-006 : ancre #brief-stories non brisée par US-007
    expect($content)->toContain('id="brief-stories"');
});
