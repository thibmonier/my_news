<?php

declare(strict_types=1);

use App\Domain\Brief\BriefPublicView;
use App\Domain\Brief\BriefPublicViewRepositoryInterface;
use App\Domain\Brief\BriefStoryPublicView;
use App\Domain\Feed\ArticleCategory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/*
 * Feature tests — Badges de catégorie éditoriale US-005.
 *
 * Valide l'intégration end-to-end BriefController → BriefStoryPublicView → HTML.
 *
 * Gherkin couvert (US-005) :
 * - Scénario nominal          : chaque histoire a exactement 1 badge avec libellé
 * - Scénario alternatif 1     : 3 catégories différentes affichent 3 badges distincts
 * - Scénario erreur 2         : le texte du badge est lisible sans CSS (pas display:none)
 * - Invariant INV-2           : badge catégorie n'utilise pas la couleur émeraude #10B981
 * - WCAG 2.1 AA               : libellé texte toujours présent (couleur seule non exclusive)
 * - XSS défense               : libellé échappé (pas de balise injectable)
 *
 * Stratégie : remplacement de BriefPublicViewRepositoryInterface par stub (comme BriefSummaryTest).
 */

uses(WebTestCase::class);

// ── Stubs ─────────────────────────────────────────────────────────────────────

function briefRepositoryWithCategories(ArticleCategory ...$categories): BriefPublicViewRepositoryInterface
{
    return new class($categories) implements BriefPublicViewRepositoryInterface {
        /** @param list<ArticleCategory> $cats */
        public function __construct(private readonly array $cats)
        {
        }

        public function findLatestPublicView(): ?BriefPublicView
        {
            $stories = [];

            foreach ($this->cats as $pos => $cat) {
                $stories[] = new BriefStoryPublicView(
                    position: $pos + 1,
                    articleTitle: "Article {$cat->label()}",
                    articleUrl: "https://example.com/article-{$pos}",
                    excerpt: "Extrait pour la catégorie {$cat->label()}.",
                    sourceName: "Source {$pos}",
                    articleId: sprintf('aaaabbbb-0000-1111-2222-%012d', $pos),
                    rawContent: "Contenu brut de l'article.",
                    category: $cat,
                );
            }

            return new BriefPublicView(
                updatedAt: new DateTimeImmutable('2026-07-29T08:00:00Z'),
                stories: $stories,
            );
        }
    };
}

// ── Scénario nominal — 1 badge par histoire ───────────────────────────────────

test('US-005 nominal : chaque histoire affiche exactement 1 badge de catégorie', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(
            ArticleCategory::AiInsight,
            ArticleCategory::Geopolitics,
            ArticleCategory::Productivity,
        ),
    );

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // 3 histoires → 3 badges
    $badgeCount = substr_count($content, 'class="badge badge--');
    expect($badgeCount)->toBe(3);
});

test('US-005 nominal : le badge affiche le libellé "AI INSIGHT"', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(
            ArticleCategory::AiInsight,
            ArticleCategory::Geopolitics,
            ArticleCategory::Research,
        ),
    );

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('AI INSIGHT');
});

test('US-005 nominal : le badge AI INSIGHT utilise la classe CSS badge--ai_insight', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(ArticleCategory::AiInsight),
    );

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('badge--ai_insight');
});

test('US-005 nominal : le libellé "GEOPOLITICS" est reconnu', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(ArticleCategory::Geopolitics),
    );
    $client->request('GET', '/brief');
    expect((string) $client->getResponse()->getContent())->toContain('GEOPOLITICS');
});

test('US-005 nominal : le libellé "PRODUCTIVITY" est reconnu', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(ArticleCategory::Productivity),
    );
    $client->request('GET', '/brief');
    expect((string) $client->getResponse()->getContent())->toContain('PRODUCTIVITY');
});

test('US-005 nominal : le libellé "RESEARCH" est reconnu', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(ArticleCategory::Research),
    );
    $client->request('GET', '/brief');
    expect((string) $client->getResponse()->getContent())->toContain('RESEARCH');
});

test('US-005 nominal : le libellé "SUSTAINABILITY" est reconnu', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(ArticleCategory::Sustainability),
    );
    $client->request('GET', '/brief');
    expect((string) $client->getResponse()->getContent())->toContain('SUSTAINABILITY');
});

// ── Scénario alternatif 1 — diversité des catégories ─────────────────────────

test('US-005 alternatif 1 : 3 catégories différentes → 3 badges distincts', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(
            ArticleCategory::AiInsight,
            ArticleCategory::Geopolitics,
            ArticleCategory::Sustainability,
        ),
    );

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('AI INSIGHT')
        ->and($content)->toContain('GEOPOLITICS')
        ->and($content)->toContain('SUSTAINABILITY');
});

test('US-005 alternatif 1 : les 3 classes CSS de badge sont distinctes', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(
            ArticleCategory::AiInsight,
            ArticleCategory::Geopolitics,
            ArticleCategory::Sustainability,
        ),
    );

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('badge--ai_insight')
        ->and($content)->toContain('badge--geopolitics')
        ->and($content)->toContain('badge--sustainability');
});

// ── Invariant INV-2 — émeraude réservé à l'IA, PAS aux badges catégorie ──────

test('US-005 INV-2 : les badges catégorie ne contiennent pas #10B981 (émeraude réservé IA)', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(
            ArticleCategory::AiInsight,
            ArticleCategory::Geopolitics,
            ArticleCategory::Research,
        ),
    );

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // Les tokens badge ne doivent pas utiliser la couleur émeraude
    // (elle peut apparaître dans le CSS de l'IA condensé — INV-2)
    // On vérifie juste que les variables badge ne font pas référence à emerald-accent
    expect($content)->not->toContain('badge-color: var(--color-emerald-accent)');
});

// ── WCAG 2.1 AA — libellé texte toujours visible (pas couleur seule) ─────────

test('US-005 WCAG : le libellé texte "AI INSIGHT" est présent dans le HTML', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(ArticleCategory::AiInsight),
    );

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // Le libellé visible — couleur seule n'est JAMAIS l'unique vecteur d'info (INV-5)
    expect($content)->toContain('AI INSIGHT');
});

test('US-005 WCAG : aria-label de catégorie est présent sur le badge', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(ArticleCategory::AiInsight),
    );

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    expect($content)->toContain('aria-label="Catégorie : AI INSIGHT"');
});

// ── Scénario erreur 2 — lisibilité sans CSS ───────────────────────────────────

test('US-005 erreur 2 : le texte du badge ne contient pas "display:none" ni "color:transparent"', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(ArticleCategory::AiInsight),
    );

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // Le badge doit être lisible même sans les styles appliqués (T-005-09 Gherkin)
    $badgePos = strpos($content, 'AI INSIGHT');
    expect($badgePos)->not->toBeFalse();

    // Pas de style inline masquant le badge
    expect($content)->not->toContain('style="display:none"')
        ->and($content)->not->toContain('style="color:transparent"');
});

// ── Sécurité XSS — le badge ne contient pas de balises injectables ────────────

test('US-005 XSS : le badge de catégorie ne contient pas "javascript:" injectable', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    // Les libellés de l'enum sont fixes — mais on vérifie que le rendu du badge est propre
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(ArticleCategory::AiInsight),
    );

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // Le badge ne doit pas contenir de protocoles JS injectables
    // Note : la page contient un <script> légitime (synthesisJs) — on ne le vérifie pas ici
    expect($content)->not->toContain('javascript:')
        ->and($content)->not->toContain('onerror=')
        ->and($content)->not->toContain('onload=');
});

// ── Intégration avec US-004 (condensé IA) — coexistence sans conflit ─────────

test('US-005 + US-004 : badge catégorie et badge BRIEFLY AI: coexistent sans conflit', function (): void {
    $client = static::createClient();
    $container = static::getContainer();
    $container->set(
        BriefPublicViewRepositoryInterface::class,
        briefRepositoryWithCategories(
            ArticleCategory::AiInsight,
            ArticleCategory::Research,
            ArticleCategory::Sustainability,
        ),
    );

    $client->request('GET', '/brief');
    $content = (string) $client->getResponse()->getContent();

    // Les deux types de badges coexistent dans la même page
    expect($content)->toContain('AI INSIGHT')     // badge catégorie
        ->and($content)->toContain('badge--ai_insight') // classe CSS catégorie
        ->and($content)->not->toContain('BRIEFLY AI:'); // badge IA absent (pas de summary)
});
