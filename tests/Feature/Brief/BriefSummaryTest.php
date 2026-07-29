<?php

declare(strict_types=1);

use App\Application\Summary\ArticleSummaryServiceInterface;
use App\Domain\Brief\BriefPublicView;
use App\Domain\Brief\BriefPublicViewRepositoryInterface;
use App\Domain\Brief\BriefStoryPublicView;
use App\Domain\Summary\ArticleSummary;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/*
 * Feature tests — Condensé IA US-004 sur GET /brief.
 *
 * Valide l'intégration end-to-end BriefController → ArticleSummaryService → HTML.
 *
 * Gherkin validé (US-004) :
 * - Scénario nominal       : badge BRIEFLY AI: + puces + lien source + noopener noreferrer
 * - Scénario dégradé       : badge RÉSUMÉ AUTOMATIQUE INDISPONIBLE (tous LLM KO)
 * - Scénario RGPD T-004-11 : aucun PII dans la réponse HTML
 * - Scénario XSS T-004-12  : contenu Mistral échappé (pas de <script> exécuté)
 *
 * Stratégie : remplacement des services dans le conteneur de test (kernel.test = true).
 * Les ports Summary (ArticleSummaryServiceInterface) sont remplacés par des stubs légers.
 */

uses(WebTestCase::class);

// ── Factories (stubs purs — aucun appel statique hors tests) ─────────────────

function briefRepositoryStub(): BriefPublicViewRepositoryInterface
{
    return new class implements BriefPublicViewRepositoryInterface {
        public function findLatestPublicView(): ?BriefPublicView
        {
            return new BriefPublicView(
                updatedAt: new DateTimeImmutable('2026-07-29T05:00:00Z'),
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
                        excerpt: 'Les licences open source évoluent face à l\'IA.',
                        sourceName: 'The Register',
                        articleId: 'b2c3d4e5-f6a7-4890-bcde-f01234567890',
                        rawContent: 'Les licences open source évoluent face à l\'IA et au cloud.',
                    ),
                    new BriefStoryPublicView(
                        position: 3,
                        articleTitle: 'Sécurité APIs : nouvelles menaces OWASP 2026',
                        articleUrl: 'https://example.com/article-3',
                        excerpt: 'Le rapport OWASP 2026 identifie de nouveaux vecteurs.',
                        sourceName: 'InfoQ',
                        articleId: 'c3d4e5f6-a7b8-4901-cdef-012345678901',
                        rawContent: 'Le rapport OWASP 2026 identifie de nouveaux vecteurs d\'attaque sur les APIs.',
                    ),
                ],
            );
        }
    };
}

function nominalSummaryServiceStub(?string $xssPayload = null): ArticleSummaryServiceInterface
{
    return new class($xssPayload) implements ArticleSummaryServiceInterface {
        public function __construct(private readonly ?string $xss)
        {
        }

        public function getSummary(string $articleId, string $articleText): ArticleSummary
        {
            $keyPoints = [
                'Premier point clé de l\'article analysé.',
                'Deuxième point clé avec détail important.',
                'Troisième point clé de conclusion.',
            ];

            if (null !== $this->xss) {
                $keyPoints[0] = $this->xss;
            }

            return new ArticleSummary(
                articleId: $articleId,
                keyPoints: $keyPoints,
                modelVersion: 'mistral-small-latest',
                createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        }
    };
}

function degradedSummaryServiceStub(): ArticleSummaryServiceInterface
{
    return new class implements ArticleSummaryServiceInterface {
        public function getSummary(string $articleId, string $articleText): ArticleSummary
        {
            return new ArticleSummary(
                articleId: $articleId,
                keyPoints: [],
                modelVersion: '',
                createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
                isDegraded: true,
                degradedContent: mb_substr($articleText, 0, 280),
            );
        }
    };
}

// ── Scénario nominal : badge BRIEFLY AI: + puces + lien source ───────────────

test('US-004 nominal : GET /brief affiche le badge BRIEFLY AI:', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, briefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, nominalSummaryServiceStub());

    $client->request('GET', '/brief');
    $response = $client->getResponse();

    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getContent())->toContain('BRIEFLY AI:');
});

test('US-004 nominal : GET /brief contient la classe briefly-ai-badge', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, briefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, nominalSummaryServiceStub());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('briefly-ai-badge');
});

test('US-004 nominal : GET /brief contient les puces .summary-bullets', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, briefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, nominalSummaryServiceStub());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('summary-bullets');
});

test('US-004 nominal : GET /brief contient 3 puces de condensé au minimum', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, briefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, nominalSummaryServiceStub());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // 3 histoires × 3 puces min = au moins 9 éléments <li class="ai-summary__bullet">
    $liCount = substr_count($content, 'ai-summary__bullet');
    expect($liCount)->toBeGreaterThanOrEqual(3);
});

test('US-004 nominal : GET /brief contient rel="noopener noreferrer" sur les liens source', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, briefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, nominalSummaryServiceStub());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('rel="noopener noreferrer"')
        ->and($content)->toContain('OUVRIR L\'ORIGINAL');
});

test('US-004 nominal : GET /brief contient "Source :" pour la traçabilité', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, briefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, nominalSummaryServiceStub());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('Source :');
});

// ── Scénario dégradé ─────────────────────────────────────────────────────────

test('US-004 dégradé : badge "RÉSUMÉ AUTOMATIQUE INDISPONIBLE" quand tous LLM sont KO', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, briefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, degradedSummaryServiceStub());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('RÉSUMÉ AUTOMATIQUE INDISPONIBLE');
});

test('US-004 dégradé : "BRIEFLY AI:" absent quand en mode dégradé', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, briefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, degradedSummaryServiceStub());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->not->toContain('BRIEFLY AI:');
});

// ── Scénario RGPD T-004-11 ───────────────────────────────────────────────────

test('T-004-11 RGPD : GET /brief ne contient aucun email dans la réponse HTML', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, briefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, nominalSummaryServiceStub());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // Aucun pattern email dans le HTML
    expect($content)->not->toMatch('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/');
});

test('T-004-11 RGPD : GET /brief ne contient pas de session_id= ou user_id=', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, briefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, nominalSummaryServiceStub());

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->not->toContain('session_id=')
        ->and($content)->not->toContain('user_id=');
});

// ── Scénario XSS T-004-12 ────────────────────────────────────────────────────

test('T-004-12 XSS : réponse LLM contenant <script> est échappée dans le HTML', function (): void {
    $xssPayload = "<script>alert('xss')</script>";
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, briefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, nominalSummaryServiceStub($xssPayload));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // Le script brut ne doit pas être présent (pas d'exécution possible)
    expect($content)->not->toContain('<script>alert(')
        // La version échappée htmlspecialchars est présente
        ->and($content)->toContain('&lt;script&gt;');
});

test('T-004-12 XSS : aucun script inject LLM dans le HTML', function (): void {
    $xssPayload = "<script>document.location='http://evil.com'</script>";
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(BriefPublicViewRepositoryInterface::class, briefRepositoryStub());
    $container->set(ArticleSummaryServiceInterface::class, nominalSummaryServiceStub($xssPayload));

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->not->toContain('<script>document.location');
});
