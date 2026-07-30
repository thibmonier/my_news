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
 * Feature tests — Featured Summary desktop (US-006) sur GET /brief.
 *
 * Gherkin validé (US-006) :
 * - Scénario nominal       : section .featured-summary présente, badge BRIEFLY AI: émeraude
 * - Ancre #brief-stories   : <ol id="brief-stories"> présent pour le CTA
 * - CTA présent            : lien "Lire le brief complet" dans la nav
 * - Mobile CSS             : .featured-summary { display:none } dans <style>
 * - Fallback Mistral KO    : texte générique SANS badge émeraude (INV-2)
 * - Absence de summary     : section absente si getForToday() retourne null
 *
 * Nommage préfixé `fs006` pour éviter les collisions globales Pest.
 * static::createClient() et static::getContainer() appelés dans les closures de test
 * (pas dans des fonctions libres — PHP fatal sinon).
 */

uses(WebTestCase::class);

// ── Stubs ─────────────────────────────────────────────────────────────────────

function fs006BriefRepositoryStub(): BriefPublicViewRepositoryInterface
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

function fs006SummaryServiceStub(): ArticleSummaryServiceInterface
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

function fs006FeaturedServiceStub(?FeaturedSummaryDTO $dto): FeaturedSummaryServiceInterface
{
    return new class($dto) implements FeaturedSummaryServiceInterface {
        public function __construct(private readonly ?FeaturedSummaryDTO $dto)
        {
        }

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
            return $this->dto;
        }
    };
}

function fs006NominalDto(): FeaturedSummaryDTO
{
    return new FeaturedSummaryDTO(
        briefId: 'b1000000-0000-4000-a000-000000000001',
        content: 'Paragraphe narratif sur les 3 histoires tech du jour.',
        modelVersion: 'mistral-small-latest',
        generatedAt: new DateTimeImmutable('2026-07-30T05:00:00Z'),
        isFallback: false,
    );
}

function fs006FallbackDto(): FeaturedSummaryDTO
{
    return new FeaturedSummaryDTO(
        briefId: 'b1000000-0000-4000-a000-000000000001',
        content: 'Voici les 3 histoires majeures du 30/07/2026.',
        modelVersion: '',
        generatedAt: new DateTimeImmutable('2026-07-30T05:00:00Z'),
        isFallback: true,
    );
}

// ── Scénario nominal : section présente avec badge émeraude ──────────────────

test('US-006 nominal : section .featured-summary présente dans GET /brief', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, fs006BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, fs006SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, fs006FeaturedServiceStub(fs006NominalDto()));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($client->getResponse()->getStatusCode())->toBe(200);
    expect($content)->toContain('class="featured-summary"');
});

test('US-006 nominal : badge BRIEFLY AI: émeraude présent (INV-2)', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, fs006BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, fs006SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, fs006FeaturedServiceStub(fs006NominalDto()));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('featured-summary__badge');
    expect($content)->toContain('BRIEFLY AI:');
});

test('US-006 nominal : contenu narratif affiché et échappé (XSS OWASP)', function (): void {
    $dtoWithXss = new FeaturedSummaryDTO(
        briefId: 'b1000000-0000-4000-a000-000000000001',
        content: '<script>alert("xss")</script>Synthèse valide.',
        modelVersion: 'mistral-small-latest',
        generatedAt: new DateTimeImmutable('2026-07-30T05:00:00Z'),
        isFallback: false,
    );

    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, fs006BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, fs006SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, fs006FeaturedServiceStub($dtoWithXss));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->not->toContain('<script>alert("xss")</script>');
    expect($content)->toContain('&lt;script&gt;');
});

// ── Ancre #brief-stories ───────────────────────────────────────────────────────

test('US-006 : ol#brief-stories présent comme ancre pour le CTA', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, fs006BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, fs006SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, fs006FeaturedServiceStub(fs006NominalDto()));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('id="brief-stories"');
});

// ── CTA "Lire le brief complet" ───────────────────────────────────────────────

test('US-006 : CTA "Lire le brief complet" présent dans la nav', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, fs006BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, fs006SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, fs006FeaturedServiceStub(fs006NominalDto()));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('Lire le brief complet');
    expect($content)->toContain('href="#brief-stories"');
});

// ── Mobile CSS masquage ────────────────────────────────────────────────────────

test('US-006 mobile : CSS contient display:none pour .featured-summary (mobile-first)', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, fs006BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, fs006SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, fs006FeaturedServiceStub(fs006NominalDto()));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('.featured-summary');
    expect($content)->toContain('display: none');
});

test('US-006 desktop : CSS contient media query min-width:768px pour .featured-summary', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, fs006BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, fs006SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, fs006FeaturedServiceStub(fs006NominalDto()));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('min-width: 768px');
});

// ── Scénario fallback : pas de badge émeraude (INV-2) ────────────────────────

test('US-006 fallback : section présente avec class featured-summary--fallback', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, fs006BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, fs006SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, fs006FeaturedServiceStub(fs006FallbackDto()));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('featured-summary--fallback');
});

test('US-006 fallback : badge BRIEFLY AI: ABSENT (INV-2 — pas accent émeraude sur non-IA)', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, fs006BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, fs006SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, fs006FeaturedServiceStub(fs006FallbackDto()));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // INV-2 : badge émeraude uniquement sur contenu IA confirmé
    // Note : "BRIEFLY AI:" apparaît dans les badges US-004 → vérifie l'attribut HTML spécifique
    // featured-summary__badge-label" (avec ") n'apparaît que dans l'attribut HTML, pas dans le CSS
    expect($content)->not->toContain('featured-summary__badge-label"');
});

test('US-006 fallback : texte générique présent dans la section', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, fs006BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, fs006SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, fs006FeaturedServiceStub(fs006FallbackDto()));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('Voici les 3 histoires majeures du 30/07/2026');
});

// ── Scénario absence de Featured Summary (null) ────────────────────────────────

test('US-006 absence : section .featured-summary absente si getForToday() retourne null', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, fs006BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, fs006SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, fs006FeaturedServiceStub(null));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($client->getResponse()->getStatusCode())->toBe(200);
    expect($content)->not->toContain('class="featured-summary"');
});

test('US-006 absence : CTA absent si getForToday() retourne null', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, fs006BriefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, fs006SummaryServiceStub());
    $container->set(FeaturedSummaryServiceInterface::class, fs006FeaturedServiceStub(null));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->not->toContain('href="#brief-stories"');
});
