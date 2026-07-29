<?php

declare(strict_types=1);

use App\Application\Summary\ArticleSummaryServiceInterface;
use App\Domain\Brief\BriefPublicView;
use App\Domain\Brief\BriefPublicViewRepositoryInterface;
use App\Domain\Brief\BriefStoryPublicView;
use App\Presentation\Controller\BriefController;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/*
 * Tests unitaires — BriefController (US-001/T-001-08).
 *
 * Teste le comportement du contrôleur en isolation (sans HTTP stack).
 * Le BriefPublicViewRepositoryInterface est mocké pour contrôler chaque scénario.
 *
 * Scénarios :
 * 1. Nominal : brief today → 200 + ViewModel correct
 * 2. Empty state : null → 200 + message "en cours de préparation"
 * 3. Exception DB → 503 + header Retry-After: 60 + log ERROR
 * 4. Redirect home → 301 vers /brief
 *
 * Gherkin validé : US-001 critères d'acceptance (nominal + erreur 1 + erreur 2).
 */

// ── Helper : BriefPublicView de test ──────────────────────────────────────────
function makeBriefPublicView(int $storyCount = 3): BriefPublicView
{
    $stories = [];

    for ($i = 1; $i <= $storyCount; ++$i) {
        $stories[] = new BriefStoryPublicView(
            position: $i,
            articleTitle: "Titre article {$i}",
            articleUrl: "https://example.com/article-{$i}",
            excerpt: "Extrait de l'article {$i} (moins de 280 caractères pour ce test unitaire).",
            sourceName: "Source Tech {$i}",
        );
    }

    return new BriefPublicView(
        updatedAt: new DateTimeImmutable('2026-07-28 14:30:00', new DateTimeZone('UTC')),
        stories: $stories,
    );
}

// ── Scénario nominal (Gherkin US-001 nominal) ─────────────────────────────────
test('index retourne 200 quand un brief est disponible', function (): void {
    $repository = $this->createMock(BriefPublicViewRepositoryInterface::class);
    $repository->method('findLatestPublicView')->willReturn(makeBriefPublicView(3));

    $logger = $this->createMock(LoggerInterface::class);

    $controller = new BriefController($repository, $this->createMock(ArticleSummaryServiceInterface::class), $logger);
    $response = $controller->index();

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($response->headers->get('Content-Type'))->toContain('text/html');
});

test('index affiche DAILY BRIEF dans le HTML', function (): void {
    $repository = $this->createMock(BriefPublicViewRepositoryInterface::class);
    $repository->method('findLatestPublicView')->willReturn(makeBriefPublicView(3));

    $controller = new BriefController($repository, $this->createMock(ArticleSummaryServiceInterface::class), $this->createMock(LoggerInterface::class));
    $response = $controller->index();

    expect($response->getContent())->toContain('DAILY BRIEF');
});

test('index affiche LAST UPDATED avec la date UTC', function (): void {
    $repository = $this->createMock(BriefPublicViewRepositoryInterface::class);
    $repository->method('findLatestPublicView')->willReturn(makeBriefPublicView(3));

    $controller = new BriefController($repository, $this->createMock(ArticleSummaryServiceInterface::class), $this->createMock(LoggerInterface::class));
    $response = $controller->index();

    expect($response->getContent())
        ->toContain('LAST UPDATED')
        ->toContain('UTC');
});

test('index affiche les 3 histoires numérotées 01, 02, 03', function (): void {
    $repository = $this->createMock(BriefPublicViewRepositoryInterface::class);
    $repository->method('findLatestPublicView')->willReturn(makeBriefPublicView(3));

    $controller = new BriefController($repository, $this->createMock(ArticleSummaryServiceInterface::class), $this->createMock(LoggerInterface::class));
    $content = $controller->index()->getContent();

    expect($content)
        ->toContain('01')
        ->toContain('02')
        ->toContain('03');
});

test('index affiche les titres, sources et extraits', function (): void {
    $repository = $this->createMock(BriefPublicViewRepositoryInterface::class);
    $repository->method('findLatestPublicView')->willReturn(makeBriefPublicView(3));

    $controller = new BriefController($repository, $this->createMock(ArticleSummaryServiceInterface::class), $this->createMock(LoggerInterface::class));
    $content = $controller->index()->getContent();

    expect($content)
        ->toContain('Titre article 1')
        ->toContain('Source Tech 1')
        // ENT_HTML5 encode l'apostrophe en &apos; (et non &#039; comme ENT_COMPAT)
        ->toContain('Extrait de l&apos;article 1');
});

test('index affiche les liens OUVRIR L\'ORIGINAL avec rel="noopener noreferrer"', function (): void {
    $repository = $this->createMock(BriefPublicViewRepositoryInterface::class);
    $repository->method('findLatestPublicView')->willReturn(makeBriefPublicView(3));

    $controller = new BriefController($repository, $this->createMock(ArticleSummaryServiceInterface::class), $this->createMock(LoggerInterface::class));
    $content = $controller->index()->getContent();

    expect($content)
        ->toContain('OUVRIR L\'ORIGINAL')
        ->toContain('rel="noopener noreferrer"');
});

test('index affiche les meta tags SEO', function (): void {
    $repository = $this->createMock(BriefPublicViewRepositoryInterface::class);
    $repository->method('findLatestPublicView')->willReturn(makeBriefPublicView(3));

    $controller = new BriefController($repository, $this->createMock(ArticleSummaryServiceInterface::class), $this->createMock(LoggerInterface::class));
    $content = $controller->index()->getContent();

    expect($content)
        ->toContain('<title>')
        ->toContain('name="description"')
        ->toContain('property="og:title"')
        ->toContain('property="og:url"');
});

// ── Scénario erreur 1 — Empty state (Gherkin US-001 erreur 1) ────────────────
test('index retourne 200 avec empty state quand aucun brief (table vide)', function (): void {
    $repository = $this->createMock(BriefPublicViewRepositoryInterface::class);
    $repository->method('findLatestPublicView')->willReturn(null);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
        ->method('info')
        ->with('no_daily_brief_available', $this->anything());

    $controller = new BriefController($repository, $this->createMock(ArticleSummaryServiceInterface::class), $logger);
    $response = $controller->index();

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($response->getContent())->toContain('en cours de préparation')
        ->and($response->getContent())->not->toContain('Fatal error')
        ->and($response->getContent())->not->toContain('Exception');
});

test('empty state ne contient pas de stacktrace PHP', function (): void {
    $repository = $this->createMock(BriefPublicViewRepositoryInterface::class);
    $repository->method('findLatestPublicView')->willReturn(null);

    $controller = new BriefController($repository, $this->createMock(ArticleSummaryServiceInterface::class), $this->createMock(LoggerInterface::class));
    $content = $controller->index()->getContent();

    expect($content)
        ->not->toContain('Stack trace')
        ->not->toContain('Throwable')
        ->not->toContain('#0 ')
        ->not->toContain('Doctrine\\');
});

// ── Scénario erreur 2 — DB indisponible (Gherkin US-001 erreur 2) ─────────────
test('index retourne 503 et log ERROR quand la base de données est indisponible', function (): void {
    $repository = $this->createMock(BriefPublicViewRepositoryInterface::class);
    $repository->method('findLatestPublicView')
        ->willThrowException(new RuntimeException('SQLSTATE[08006]: Connection refused'));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
        ->method('error')
        ->with('brief.db_error', $this->anything());

    $controller = new BriefController($repository, $this->createMock(ArticleSummaryServiceInterface::class), $logger);
    $response = $controller->index();

    expect($response->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
});

test('réponse 503 contient le header Retry-After: 60', function (): void {
    $repository = $this->createMock(BriefPublicViewRepositoryInterface::class);
    $repository->method('findLatestPublicView')
        ->willThrowException(new RuntimeException('Connection timeout'));

    $controller = new BriefController($repository, $this->createMock(ArticleSummaryServiceInterface::class), $this->createMock(LoggerInterface::class));
    $response = $controller->index();

    expect($response->headers->get('Retry-After'))->toBe('60');
});

test('réponse 503 ne contient pas de stacktrace ni de message technique', function (): void {
    $repository = $this->createMock(BriefPublicViewRepositoryInterface::class);
    $repository->method('findLatestPublicView')
        ->willThrowException(new RuntimeException('DSN=pgsql://postgres:secret@db:5432/briefly'));

    $controller = new BriefController($repository, $this->createMock(ArticleSummaryServiceInterface::class), $this->createMock(LoggerInterface::class));
    $content = $controller->index()->getContent();

    // OWASP #7 : pas de détail technique dans la réponse
    expect($content)
        ->toContain('Service temporairement indisponible')
        ->not->toContain('DSN=')
        ->not->toContain('postgres:')
        ->not->toContain('Stack trace')
        ->not->toContain('Exception');
});

// ── Redirect home (T-001-03) ────────────────────────────────────────────────
test('home retourne un redirect 301 vers /brief', function (): void {
    $controller = new BriefController(
        $this->createMock(BriefPublicViewRepositoryInterface::class),
        $this->createMock(ArticleSummaryServiceInterface::class),
        $this->createMock(LoggerInterface::class),
    );

    $response = $controller->home();

    expect($response->getStatusCode())->toBe(301)
        ->and($response->headers->get('Location'))->toBe('/brief');
});
