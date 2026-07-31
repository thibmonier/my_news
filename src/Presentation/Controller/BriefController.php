<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Brief\FeaturedSummary\FeaturedSummaryServiceInterface;
use App\Application\Summary\ArticleSummaryServiceInterface;
use App\Domain\Brief\BriefPublicViewRepositoryInterface;
use App\Domain\Brief\FeaturedSummaryDTO;
use App\Domain\Summary\ArticleSummary;
use App\Presentation\ViewModel\DailyBriefViewModel;
use App\Presentation\ViewModel\StoryViewModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur — Page web publique du Daily Brief (US-001 + US-004 + US-006).
 *
 * Routes :
 *   GET /brief → 200 (page brief ou empty state)
 *   GET /      → 301 /brief (SEO — US-001 conversation §1)
 *
 * Accès : PUBLIC (IS_AUTHENTICATED_ANONYMOUSLY — pas de firewall sur ces routes).
 * Pas de CSRF sur GET (constitution §6, US-001 critère sécurité).
 *
 * Gestion des états :
 * - Brief disponible    → 200 + HTML avec Featured Summary (desktop) + 3 histoires + condensés IA
 * - Table vide          → 200 + message "Brief en cours de préparation"
 * - Erreur base données → 503 + message générique + header Retry-After: 60
 *
 * US-004 — Condensé IA par article :
 * - Appel à ArticleSummaryService pour chaque histoire avant le rendu Twig
 * - Badge "BRIEFLY AI:" accent émeraude #10B981
 * - Mode dégradé : badge "RÉSUMÉ AUTOMATIQUE INDISPONIBLE" si LLM indispo
 *
 * US-006 — Featured Summary desktop :
 * - Section narrative en tête du /brief (badge BRIEFLY AI: émeraude)
 * - Masquée sur mobile (< 768px) via CSS `display:none`
 * - CTA sticky "Lire le brief complet" → ancre #brief-stories (même page)
 * - Fallback : texte générique sans badge émeraude si Mistral KO
 * - OWASP XSS : contenu Mistral échappé via htmlspecialchars()
 *
 * SÉCURITÉ OWASP #7 (Mishandling Exceptional Conditions) :
 * - Jamais de stacktrace dans la réponse HTML
 * - Messages d'erreur génériques côté client
 * - Logging côté serveur sans données personnelles
 *
 * Couche Presentation — dépend de Domain + Application.
 * Deptrac : Presentation:[Domain, Application].
 */
final class BriefController
{
    public function __construct(
        private readonly BriefPublicViewRepositoryInterface $briefRepository,
        private readonly ArticleSummaryServiceInterface $summaryService,
        private readonly LoggerInterface $logger,
        private readonly ?FeaturedSummaryServiceInterface $featuredSummaryService = null,
    ) {
    }

    /**
     * Page publique du Daily Brief.
     */
    #[Route('/brief', name: 'app_brief', methods: ['GET'])]
    public function index(): Response
    {
        try {
            $publicView = $this->briefRepository->findLatestPublicView();
        } catch (\Throwable $e) {
            // Erreur technique (DB timeout, crash PostgreSQL, etc.)
            // OWASP #7 : log avec contexte technique, réponse générique sans détail
            $this->logger->error('brief.db_error', [
                'event' => 'brief.db_error',
                'error_class' => $e::class,
                'error' => $e->getMessage(),
                // Pas de stack trace complète en prod (OWASP #7)
            ]);

            return $this->serviceUnavailableResponse();
        }

        if (null === $publicView) {
            // Table vide — premier démarrage ou aucun brief en état 'ready'
            $this->logger->info('no_daily_brief_available', [
                'event' => 'no_daily_brief_available',
                // Pas de données personnelles dans les logs (RGPD + INV-6)
            ]);

            return $this->emptyStateResponse();
        }

        // ── US-004 : pré-génération des condensés IA pour les 3 histoires ──────
        $summariesByPosition = [];

        foreach ($publicView->stories as $story) {
            if ('' === $story->articleId) {
                continue;
            }

            try {
                $summariesByPosition[$story->position] = $this->summaryService->getSummary(
                    $story->articleId,
                    $story->rawContent,
                );
            } catch (\Throwable $e) {
                // Dégradé silencieux — un condensé manquant ne bloque pas la page
                $this->logger->warning('brief.summary_generation_failed', [
                    'event' => 'brief.summary_generation_failed',
                    'position' => $story->position,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $viewModel = DailyBriefViewModel::fromPublicView($publicView, $summariesByPosition);

        // ── US-006 : Récupération du Featured Summary (desktop) ──────────────
        $featuredSummary = null;

        if (null !== $this->featuredSummaryService) {
            try {
                $featuredSummary = $this->featuredSummaryService->getForToday(
                    new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                );
            } catch (\Throwable $e) {
                // Non-bloquant : la section est simplement absente (pas d'erreur visible)
                $this->logger->warning('featured_summary.display_failed', [
                    'event' => 'featured_summary.display_failed',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new Response(
            $this->renderBriefHtml($viewModel, $featuredSummary),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * Page d'accueil — redirect SEO 301 vers /brief (US-001 conversation §1).
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): RedirectResponse
    {
        return new RedirectResponse('/brief', Response::HTTP_MOVED_PERMANENTLY);
    }

    // ── HTML rendering ─────────────────────────────────────────────────────────

    /**
     * Génère le HTML principal du brief avec Featured Summary (US-006) + 3 histoires.
     *
     * Utilise les design tokens CSS (design-tokens.css) inlinés pour Sprint 1.
     * SEO : title, meta description, og:title, og:description, og:url (US-001/T-001-05).
     * Turbo Drive : data-turbo="true" + import Hotwire (US-001 scénario alternatif 2).
     * Liens OUVRIR L'ORIGINAL : rel="noopener noreferrer" (US-001 conversation §6).
     * Invariant INV-2 : accent émeraude (#10B981) réservé exclusivement à l'IA.
     *
     * US-006 :
     * - Section .featured-summary (desktop uniquement — display:none sur mobile)
     * - id="brief-stories" sur la liste des histoires (ancre CTA)
     * - CTA "Lire le brief complet" (#brief-stories) dans la nav (sticky)
     */
    private function renderBriefHtml(DailyBriefViewModel $vm, ?FeaturedSummaryDTO $featuredSummary = null): string
    {
        $storiesHtml = '';

        foreach ($vm->stories as $story) {
            $storiesHtml .= $this->renderStory($story);
        }

        $pageTitle = htmlspecialchars('DAILY BRIEF — Briefly AI', \ENT_QUOTES | \ENT_HTML5);
        $metaDescription = "Votre synth\u{00E8}se quotidienne des 3 histoires majeures de l'actualit\u{00E9} tech.";
        $ogUrl = 'https://briefly.ai/brief'; // @TODO: injecter depuis env en Sprint 2
        $lastUpdated = htmlspecialchars($vm->lastUpdatedFormatted, \ENT_QUOTES | \ENT_HTML5);

        $featuredSummaryHtml = $this->renderFeaturedSummary($featuredSummary);
        $ctaHtml = null !== $featuredSummary
            ? '<a href="#brief-stories" class="cta-read-brief" aria-label="Lire les 3 histoires du brief">Lire le brief complet</a>'
            : '';

        return <<<HTML
            <!DOCTYPE html>
            <html lang="fr" data-turbo="true">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>{$pageTitle}</title>
                <meta name="description" content="{$metaDescription}">
                <meta property="og:title" content="DAILY BRIEF — Briefly AI">
                <meta property="og:description" content="{$metaDescription}">
                <meta property="og:url" content="{$ogUrl}">
                <meta property="og:type" content="website">
                {$this->designTokensCss()}
                {$this->pageCss()}
                {$this->badgeCss()}
                {$this->summaryCss()}
                {$this->synthesisCss()}
                {$this->featuredSummaryCss()}
                {$this->progressBarCss()}
            </head>
            <body>
                <!-- P1-2 : skip-link -->
                <a href="#main-content" class="skip-link">Aller au contenu principal</a>
                <div
                    data-controller="progress-bar"
                    role="progressbar"
                    aria-valuenow="0"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    class="progress-bar"
                    style="width:0%"
                ></div>
                <header class="site-header" role="banner">
                    <nav class="nav-container" aria-label="Navigation principale">
                        <a href="/brief" class="logo" aria-label="Briefly AI — accueil">BRIEFLY</a>
                        {$ctaHtml}
                    </nav>
                </header>

                <main class="main-content" id="main-content">
                    <div class="brief-container">
                        <div class="brief-header">
                            <h1 class="brief-title">DAILY BRIEF</h1>
                            <p class="brief-timestamp">
                                <time>LAST UPDATED {$lastUpdated}</time>
                            </p>
                        </div>

                        {$featuredSummaryHtml}

                        <ol class="stories-list" id="brief-stories" aria-label="Les 3 histoires du jour">
                            {$storiesHtml}
                        </ol>
                    </div>
                </main>

                <footer class="site-footer" role="contentinfo">
                    <p>© Briefly AI — fort signal, faible bruit, ton éditorial.</p>
                </footer>

                {$this->synthesisJs()}
                {$this->progressBarJs()}
            </body>
            </html>
            HTML;
    }

    /**
     * Génère le HTML de la section Featured Summary (US-006).
     *
     * Cas nominal (isFallback = false) :
     * - Classe `.featured-summary` (masquage mobile via CSS)
     * - Badge "BRIEFLY AI:" émeraude (INV-2 — accent émeraude UNIQUEMENT pour l'IA)
     * - Texte narratif échappé via htmlspecialchars() (OWASP XSS)
     *
     * Cas fallback (isFallback = true ou $summary = null) :
     * - Si null → retourne '' (section absente — pas d'erreur visible)
     * - Si isFallback → texte sans badge émeraude (INV-2 respecté)
     *
     * OWASP XSS : contenu Mistral considéré comme non fiable — toujours échappé.
     */
    private function renderFeaturedSummary(?FeaturedSummaryDTO $summary): string
    {
        if (null === $summary) {
            return '';
        }

        // XSS : échappement systématique du contenu IA (US-006 scénario erreur RGPD)
        $content = htmlspecialchars($summary->content, \ENT_QUOTES | \ENT_HTML5);

        if ($summary->isFallback) {
            // Cas fallback : texte sans badge émeraude (INV-2 — pas d'accent émeraude si non-IA)
            return <<<HTML
                <section class="featured-summary featured-summary--fallback" aria-label="Synthèse éditoriale du brief">
                    <p class="featured-summary__text">{$content}</p>
                </section>
                HTML;
        }

        // Cas nominal : badge BRIEFLY AI: émeraude (INV-2)
        return <<<HTML
            <section class="featured-summary" aria-label="Synthèse éditoriale du brief">
                <div class="featured-summary__badge">
                    <span class="material-symbols-rounded featured-summary__icon" aria-hidden="true">auto_awesome</span>
                    <span class="ai-summary__badge-text featured-summary__badge-label">BRIEFLY AI:</span>
                </div>
                <p class="featured-summary__text">{$content}</p>
            </section>
            HTML;
    }

    /**
     * Génère le HTML d'une story individuelle avec son condensé IA (US-004) et badge catégorie (US-005).
     *
     * Badge catégorie (US-005) :
     * - Couleur distincte par catégorie (pas émeraude #10B981 — INV-2)
     * - Libellé texte toujours présent (WCAG 2.1 AA — couleur + texte, pas couleur seule)
     * - Échappe le libellé via htmlspecialchars (défense OWASP XSS)
     *
     * Bloc IA (US-004) :
     * - Badge "BRIEFLY AI:" avec icône auto_awesome (émeraude #10B981 INV-2)
     * - Liste de 3-4 puces (keyPoints échappées — OWASP XSS)
     * - Lien "Source : [nom]" + bouton "OUVRIR L'ORIGINAL" (rel="noopener noreferrer")
     * - Mode dégradé : badge "RÉSUMÉ AUTOMATIQUE INDISPONIBLE" sans couleur émeraude
     *
     * Le lien "OUVRIR L'ORIGINAL" utilise rel="noopener noreferrer" (US-001/T-001-05 + OWASP A01).
     */
    private function renderStory(StoryViewModel $story): string
    {
        $position = htmlspecialchars($story->position, \ENT_QUOTES | \ENT_HTML5);
        $title = htmlspecialchars($story->title, \ENT_QUOTES | \ENT_HTML5);
        $source = htmlspecialchars($story->sourceName, \ENT_QUOTES | \ENT_HTML5);
        $excerpt = htmlspecialchars($story->excerpt, \ENT_QUOTES | \ENT_HTML5);
        $sourceUrl = htmlspecialchars($story->sourceUrl, \ENT_QUOTES | \ENT_HTML5);
        // Identifiant unique de la zone de synthèse (position 01/02/03)
        $zoneId = 'synthesis-result-' . $story->position;

        // US-005 — Badge catégorie éditoriale
        // Sécurité XSS : le libellé est une valeur fixe de l'enum mais htmlspecialchars défensif
        $categoryValue = htmlspecialchars($story->category->value, \ENT_QUOTES | \ENT_HTML5);
        $categoryLabel = htmlspecialchars($story->category->label(), \ENT_QUOTES | \ENT_HTML5);
        $categoryBadgeHtml = <<<HTML
            <span class="badge badge--{$categoryValue}" aria-label="Catégorie : {$categoryLabel}">{$categoryLabel}</span>
            HTML;

        $summaryHtml = $this->renderSummaryBlock($story->summary, $story->sourceName, $story->sourceUrl);

        return <<<HTML
            <li class="story-card" data-position="{$position}">
                <span class="story-number" aria-hidden="true">{$position}</span>
                <div class="story-body">
                    <h2 class="story-title">{$title}</h2>
                    <p class="story-source">{$source}</p>
                    {$categoryBadgeHtml}
                    {$summaryHtml}
                    <p class="story-excerpt">{$excerpt}</p>
                    <div class="synthesis-level-selector" role="group" aria-label="Niveau de synthèse">
                        <label class="level-label">Niveau :</label>
                        <label class="level-option">
                            <input type="radio" name="level-{$position}" value="concise" checked aria-label="Concis (~200 mots, 3 points)">
                            <span>Concise</span>
                        </label>
                        <label class="level-option">
                            <input type="radio" name="level-{$position}" value="detailed" aria-label="Détaillé (~500 mots, 5 points)">
                            <span>Detailed</span>
                        </label>
                        <label class="level-option">
                            <input type="radio" name="level-{$position}" value="narrative" aria-label="Narratif (~800 mots, prose éditoriale)">
                            <span>Narrative</span>
                        </label>
                    </div>
                    <div class="story-actions">
                        <a
                            href="{$sourceUrl}"
                            class="story-link"
                            rel="noopener noreferrer"
                            target="_blank"
                            aria-label="Ouvrir l'article original : {$title}"
                        >OUVRIR L'ORIGINAL →</a>
                        <button
                            class="synthesis-btn"
                            data-url="{$sourceUrl}"
                            data-zone="{$zoneId}"
                            data-level-group="level-{$position}"
                            onclick="handleSynthesis(this)"
                            aria-label="Générer une synthèse IA pour : {$title}"
                        >GENERATE AI SUMMARY</button>
                    </div>
                    <div id="{$zoneId}" class="synthesis-zone" role="region" aria-live="polite" aria-label="Synthèse IA de l'article"></div>
                </div>
            </li>
            HTML;
    }

    /**
     * Génère le bloc HTML du condensé IA (US-004).
     *
     * Cas nominal (isDegraded = false) :
     * - Badge "BRIEFLY AI:" émeraude avec icône auto_awesome (INV-2)
     * - Liste <ul> de 3-4 puces échappées via htmlspecialchars (OWASP XSS)
     * - Lien "Source : [nom]" + bouton "OUVRIR L'ORIGINAL" rel="noopener noreferrer"
     *
     * Cas dégradé (isDegraded = true ou $summary = null) :
     * - Badge "RÉSUMÉ AUTOMATIQUE INDISPONIBLE" (pas de couleur émeraude)
     * - Contenu RSS brut échappé (≤ 280 chars)
     *
     * Sécurité XSS : TOUT le contenu IA est échappé via htmlspecialchars().
     * Aucun filtre `raw` — la réponse Mistral/OpenAI est traitée comme non fiable.
     */
    private function renderSummaryBlock(?ArticleSummary $summary, string $sourceName, string $sourceUrl): string
    {
        if (null === $summary) {
            // Pas de condensé disponible — aucun bloc affiché
            return '';
        }

        $escapedSourceName = htmlspecialchars($sourceName, \ENT_QUOTES | \ENT_HTML5);
        $escapedSourceUrl = htmlspecialchars($sourceUrl, \ENT_QUOTES | \ENT_HTML5);

        if ($summary->isDegraded) {
            $degradedContent = htmlspecialchars($summary->degradedContent, \ENT_QUOTES | \ENT_HTML5);

            return <<<HTML
                <div class="ai-summary ai-summary--degraded" role="region" aria-label="Résumé automatique indisponible">
                    <div class="ai-summary__badge ai-summary__badge--degraded">
                        <span class="ai-summary__badge-text">RÉSUMÉ AUTOMATIQUE INDISPONIBLE</span>
                    </div>
                    <p class="ai-summary__degraded-content">{$degradedContent}</p>
                    <p class="ai-summary__source">Source : {$escapedSourceName}</p>
                    <a
                        href="{$escapedSourceUrl}"
                        class="ai-summary__open-link"
                        rel="noopener noreferrer"
                        target="_blank"
                    >OUVRIR L'ORIGINAL</a>
                </div>
                HTML;
        }

        // ── Cas nominal : condensé IA avec badge émeraude (INV-2) ─────────────
        $bulletsHtml = '';

        foreach ($summary->keyPoints as $bullet) {
            // XSS : échappement systématique de la réponse LLM (US-004 Conversation §6)
            $escapedBullet = htmlspecialchars($bullet, \ENT_QUOTES | \ENT_HTML5);
            $bulletsHtml .= "<li class=\"ai-summary__bullet\">{$escapedBullet}</li>\n";
        }

        return <<<HTML
            <div class="ai-summary ai-summary--nominal briefly-ai-badge" role="region" aria-label="Condensé IA Briefly AI">
                <div class="ai-summary__badge">
                    <span class="material-symbols-rounded ai-summary__icon" aria-hidden="true">auto_awesome</span>
                    <span class="ai-summary__badge-text">BRIEFLY AI:</span>
                </div>
                <ul class="ai-summary__bullets summary-bullets">
                    {$bulletsHtml}
                </ul>
                <div class="ai-summary__footer">
                    <p class="ai-summary__source">Source : <span>{$escapedSourceName}</span></p>
                    <a
                        href="{$escapedSourceUrl}"
                        class="ai-summary__open-link"
                        rel="noopener noreferrer"
                        target="_blank"
                    >OUVRIR L'ORIGINAL</a>
                </div>
            </div>
            HTML;
    }

    /**
     * CSS des badges de catégorie éditoriale (US-005).
     *
     * Couleurs tokens par catégorie — JAMAIS émeraude #10B981 (réservé badge IA — INV-2).
     * WCAG 2.1 AA : le libellé texte est toujours présent (couleur seule non exclusive — INV-5).
     * Responsive : visible < 768px sans troncature (flex-wrap sur .story-body).
     *
     * Tokens CSS ajoutés à :root ici (badge colors) pour garder designTokensCss() stable.
     */
    private function badgeCss(): string
    {
        return <<<'CSS_BLOCK'
            <style>
            /* ── Tokens couleur badges catégorie (US-005) ─────────────────────────── */
            :root {
              --color-badge-violet:     #7C3AED;
              --color-badge-red:        #DC2626;
              --color-badge-blue:       #2563EB;
              --color-badge-orange:     #EA580C;
              --color-badge-green-dark: #15803D;
            }
            /* ── Badge base (US-005) ──────────────────────────────────────────────── */
            .badge {
              display: inline-block;
              font-family: var(--font-meta);
              font-size: 10px;
              letter-spacing: 0.08em;
              font-weight: 700;
              text-transform: uppercase;
              padding: 2px 6px;
              border-radius: 2px;
              border: 1px solid currentColor;
              margin-bottom: 0.5rem;
              /* Contraste WCAG 2.1 AA : texte coloré sur fond blanc/clair */
              background: transparent;
              /* Couleur définie par la classe modificatrice --{category} */
              color: var(--badge-color, var(--color-outline));
            }
            /* ── Modificateurs par catégorie ─────────────────────────────────────── */
            .badge--ai_insight    { --badge-color: var(--color-badge-violet);     }
            .badge--geopolitics   { --badge-color: var(--color-badge-red);        }
            .badge--productivity  { --badge-color: var(--color-badge-blue);       }
            .badge--research      { --badge-color: var(--color-badge-orange);     }
            .badge--sustainability { --badge-color: var(--color-badge-green-dark); }
            /* ── Dark mode (contrastes WCAG AA préservés) ────────────────────────── */
            @media (prefers-color-scheme: dark) {
              :root:not([data-theme="light"]) {
                --color-badge-violet:     #A78BFA;
                --color-badge-red:        #FCA5A5;
                --color-badge-blue:       #93C5FD;
                --color-badge-orange:     #FDBA74;
                --color-badge-green-dark: #4ADE80;
              }
            }
            </style>
            CSS_BLOCK;
    }

    /**
     * CSS du bloc condensé IA (US-004).
     *
     * Invariant INV-2 : accent émeraude #10B981 RÉSERVÉ aux éléments IA.
     * Classe `.briefly-ai-badge` : identifiant de test E2E (T-004-12).
     * Classe `.summary-bullets` : sélecteur de test E2E (T-004-12).
     */
    private function summaryCss(): string
    {
        return <<<'CSS_BLOCK'
            <style>
            /* ── Condensé IA (US-004) ─────────────────────────────────────────────── */
            .ai-summary {
              border-radius: var(--radius);
              padding: 0.875rem 1rem;
              margin-bottom: 1rem;
            }
            /* Cas nominal : bordure émeraude (INV-2 — réservé à l'IA) */
            .ai-summary--nominal {
              background: linear-gradient(135deg, rgba(16,185,129,0.05) 0%, transparent 100%);
              border: 1px solid var(--color-emerald-accent);
            }
            /* Cas dégradé : bordure neutre (pas de couleur émeraude — INV-2) */
            .ai-summary--degraded {
              background: var(--color-surface-card);
              border: 1px dashed var(--color-surface-border);
            }
            .ai-summary__badge {
              display: flex;
              align-items: center;
              gap: 0.25rem;
              margin-bottom: 0.5rem;
            }
            .ai-summary__icon {
              font-size: 14px;
              color: var(--color-emerald-accent);
              font-family: 'Material Symbols Rounded';
              font-style: normal;
              font-weight: normal;
            }
            .ai-summary__badge-text {
              font-family: var(--font-meta);
              font-size: 10px;
              letter-spacing: 0.1em;
              font-weight: 700;
              text-transform: uppercase;
            }
            /* Badge émeraude foncé pour le nominal (INV-2 : signal IA conservé, contraste AA ≥4.5:1) */
            .ai-summary--nominal .ai-summary__badge-text {
              color: #047857; /* émeraude foncée ≈5.25:1 sur blanc — corrige P1-1 */
            }
            /* Badge neutre pour le dégradé */
            .ai-summary--degraded .ai-summary__badge-text {
              color: var(--color-outline);
              letter-spacing: 0.05em;
            }
            .ai-summary__bullets {
              list-style: none;
              padding: 0;
              margin: 0 0 0.75rem 0;
            }
            .ai-summary__bullet {
              font-size: 14px;
              line-height: 1.5;
              color: var(--color-on-surface-variant);
              padding: 0.2rem 0 0.2rem 0.625rem;
              border-left: 2px solid var(--color-emerald-accent);
              margin-bottom: 0.25rem;
            }
            .ai-summary__footer {
              display: flex;
              align-items: center;
              gap: 1rem;
              flex-wrap: wrap;
            }
            .ai-summary__source {
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              color: var(--color-outline);
              flex: 1;
            }
            .ai-summary__source span { color: var(--color-on-surface-variant); }
            .ai-summary__open-link {
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              font-weight: 700;
              text-transform: uppercase;
              color: #047857; /* P1-1 : émeraude foncée ≈5.25:1 sur blanc */
              text-decoration: none;
              white-space: nowrap;
            }
            .ai-summary--degraded .ai-summary__open-link {
              color: var(--color-slate-gray);
            }
            .ai-summary__open-link:hover { text-decoration: underline; }
            .ai-summary__degraded-content {
              font-size: var(--fs-body-md);
              color: var(--color-on-surface-variant);
              font-style: italic;
              margin-bottom: 0.5rem;
            }
            /* Google Material Symbols (inline font via CDN-free fallback) */
            @font-face {
              font-family: 'Material Symbols Rounded';
              font-style: normal;
              font-weight: 400;
              src: url(https://fonts.gstatic.com/s/materialsymbolsrounded/v222/syl0-zNym6-2jv1w84WQhX9R_jn9T5aQ.woff2) format('woff2');
            }
            </style>
            CSS_BLOCK;
    }

    /**
     * Script JS inline pour la synthèse IA (US-010).
     *
     * Pas de bundler en Sprint 1 — JS vanilla inline.
     * Action : POST /api/v1/synthesis avec l'URL de l'article.
     * - Affiche un skeleton loading pendant l'appel (max 10s timeout côté JS)
     * - Met à jour le Turbo Frame (zone synthesis-result-{pos}) avec le résultat
     * - Gère les erreurs : 401 → invitation à se connecter, 422 → URL invalide,
     *   429 → quota dépassé, 503 → service indisponible
     * - Aucun PII envoyé (juste l'URL de l'article — public)
     */
    private function synthesisJs(): string
    {
        return <<<'JS_BLOCK'
            <script>
            async function handleSynthesis(btn) {
              const url       = btn.dataset.url;
              const zone      = document.getElementById(btn.dataset.zone);
              const levelGroup = btn.dataset.levelGroup;
              if (!url || !zone) return;

              // Lire le niveau sélectionné (US-011)
              const levelInput = levelGroup
                ? document.querySelector(`input[name="${levelGroup}"]:checked`)
                : null;
              const level = levelInput ? levelInput.value : 'concise';

              // Timeout adapté au niveau (concise 15s, detailed 30s, narrative 50s JS)
              const jsTimeouts = { concise: 16000, detailed: 32000, narrative: 50000 };
              const jsTimeout  = jsTimeouts[level] || 16000;

              // Skeleton loading
              btn.disabled = true;
              btn.textContent = 'GENERATING…';
              zone.innerHTML = '<div class="synthesis-skeleton" aria-busy="true">Génération en cours…</div>';

              const controller = new AbortController();
              const timeout = setTimeout(() => controller.abort(), jsTimeout);

              try {
                const resp = await fetch('/api/v1/synthesis', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                  body: JSON.stringify({ url, level }),
                  signal: controller.signal,
                });
                clearTimeout(timeout);

                if (resp.status === 401 || resp.status === 403) {
                  zone.innerHTML = '<div class="synthesis-error">Connectez-vous pour générer une synthèse IA. <a href="/login">Se connecter →</a></div>';
                  btn.textContent = 'GENERATE AI SUMMARY';
                  btn.disabled = false;
                  return;
                }

                if (resp.status === 429) {
                  zone.innerHTML = '<div class="synthesis-error">Quota épuisé — 3 synthèses gratuites utilisées aujourd\'hui.</div>';
                  btn.textContent = 'GENERATE AI SUMMARY';
                  btn.disabled = false;
                  return;
                }

                if (resp.status === 422) {
                  let msg = 'URL invalide — vérifiez le format de l\'adresse.';
                  try {
                    const errData = await resp.json();
                    if (errData && errData.detail) msg = errData.detail;
                  } catch (e) { /* ignore */ }
                  zone.innerHTML = `<div class="synthesis-error">${escHtml(msg)}</div>`;
                  btn.textContent = 'GENERATE AI SUMMARY';
                  btn.disabled = false;
                  return;
                }

                if (!resp.ok) {
                  let errorMsg = 'Service temporairement indisponible — réessayez dans quelques instants.';
                  try {
                    const errData = await resp.json();
                    if (errData && errData.detail) errorMsg = errData.detail;
                  } catch (e) { /* ignore */ }
                  zone.innerHTML = `<div class="synthesis-error">${escHtml(errorMsg)}</div>`;
                  btn.textContent = 'GENERATE AI SUMMARY';
                  btn.disabled = false;
                  return;
                }

                const data = await resp.json();
                zone.innerHTML = renderSynthesis(data);
                btn.textContent = '✓ SYNTHÈSE GÉNÉRÉE';
              } catch (e) {
                clearTimeout(timeout);
                const isTimeout = e.name === 'AbortError';
                zone.innerHTML = isTimeout
                  ? '<div class="synthesis-error">Délai dépassé — réessayez dans quelques instants.</div>'
                  : '<div class="synthesis-error">Service temporairement indisponible — réessayez dans quelques instants.</div>';
                btn.textContent = 'GENERATE AI SUMMARY';
                btn.disabled = false;
              }
            }

            function renderSynthesis(data) {
              const keyPointsHtml = (data.keyPoints || [])
                .map(kp => `<li class="synthesis-kp">${escHtml(kp)}</li>`)
                .join('');
              const sourcesHtml = (data.sources || [])
                .map(s => escHtml(s))
                .join(', ');
              const partialBanner = data.isPartial
                ? '<p class="synthesis-partial">Contenu partiel — accès limité à la source</p>'
                : '';
              const originalUrl = data.originalUrl || '';
              const content     = data.content || '';
              // Badge niveau (US-011 — INV-2 accent émeraude réservé à l'IA)
              const levelLabel  = { concise: 'Concise', detailed: 'Detailed', narrative: 'Narrative' };
              const levelBadge  = data.level
                ? `<span class="synthesis-level-badge">${escHtml(levelLabel[data.level] || data.level)}</span>`
                : '';

              return `<div class="synthesis-result">
                <div class="synthesis-badge">BRIEFLY AI ${levelBadge}</div>
                <p class="synthesis-content">${escHtml(content)}</p>
                ${keyPointsHtml ? `<ol class="synthesis-keypoints">${keyPointsHtml}</ol>` : ''}
                ${sourcesHtml ? `<p class="synthesis-sources">Sources : ${sourcesHtml}</p>` : ''}
                ${partialBanner}
                ${originalUrl ? `<a href="${escHtml(originalUrl)}" class="synthesis-original-link" rel="noopener noreferrer" target="_blank">OUVRIR L'ORIGINAL →</a>` : ''}
              </div>`;
            }

            function escHtml(str) {
              return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
            }
            </script>
            JS_BLOCK;
    }

    /**
     * CSS additionnel pour la zone de synthèse IA (US-010).
     *
     * Invariant INV-2 : accent émeraude (#10B981) réservé exclusivement au bloc "BRIEFLY AI:".
     */
    private function synthesisCss(): string
    {
        return <<<'CSS_BLOCK'
            <style>
            .story-actions {
              display: flex;
              gap: 0.5rem;
              align-items: center;
              flex-wrap: wrap;
              margin-bottom: 0.75rem;
            }
            .synthesis-btn {
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              color: #047857; /* P1-1 : émeraude foncée ≈5.25:1 sur blanc */
              background: transparent;
              border: 1px solid var(--color-emerald-accent);
              padding: 0.375rem 0.75rem;
              border-radius: var(--radius);
              cursor: pointer;
              font-weight: 600;
              text-transform: uppercase;
              transition: background 0.15s ease, color 0.15s ease;
            }
            .synthesis-btn:hover:not(:disabled) {
              background: var(--color-emerald-accent);
              color: #fff;
            }
            .synthesis-btn:disabled { opacity: 0.6; cursor: not-allowed; }
            .synthesis-zone { margin-top: 0.75rem; }
            .synthesis-skeleton {
              background: var(--color-surface-border);
              border-radius: var(--radius);
              padding: 1rem;
              color: var(--color-on-surface-variant);
              font-style: italic;
              animation: pulse 1.5s ease-in-out infinite;
            }
            @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.5} }
            .synthesis-result {
              background: linear-gradient(135deg, rgba(16,185,129,0.04) 0%, transparent 100%);
              border: 1px solid var(--color-emerald-accent);
              border-radius: var(--radius);
              padding: 1rem 1.25rem;
            }
            .synthesis-badge {
              font-family: var(--font-meta);
              font-size: 10px;
              letter-spacing: 0.1em;
              font-weight: 700;
              color: #047857; /* P1-1 : émeraude foncée ≈5.25:1 sur blanc */
              text-transform: uppercase;
              margin-bottom: 0.5rem;
            }
            .synthesis-content {
              font-size: var(--fs-body-md);
              line-height: var(--lh-body-md);
              color: var(--color-on-surface);
              margin-bottom: 0.75rem;
            }
            .synthesis-keypoints {
              list-style: none;
              padding: 0;
              margin-bottom: 0.75rem;
            }
            .synthesis-kp {
              font-size: 14px;
              line-height: 1.5;
              color: var(--color-on-surface-variant);
              padding: 0.25rem 0;
              border-left: 2px solid var(--color-emerald-accent);
              padding-left: 0.5rem;
              margin-bottom: 0.25rem;
            }
            .synthesis-sources {
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              color: var(--color-outline);
              margin-bottom: 0.5rem;
            }
            .synthesis-partial {
              font-size: var(--fs-label);
              color: #f59e0b;
              margin-bottom: 0.5rem;
            }
            .synthesis-original-link {
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              color: #047857; /* P1-1 : émeraude foncée ≈5.25:1 sur blanc */
              text-decoration: none;
              font-weight: 600;
              text-transform: uppercase;
            }
            .synthesis-original-link:hover { text-decoration: underline; }
            .synthesis-error {
              background: #fee2e2;
              color: #dc2626;
              border-radius: var(--radius);
              padding: 0.75rem;
              font-size: 14px;
            }
            .synthesis-error a { color: #dc2626; font-weight: 600; }
            /* ── Sélecteur de niveau (US-011) ────────────────────────────────────── */
            .synthesis-level-selector {
              display: flex;
              align-items: center;
              gap: 0.5rem;
              flex-wrap: wrap;
              margin-bottom: 0.5rem;
            }
            .level-label {
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              color: var(--color-outline);
              text-transform: uppercase;
            }
            .level-option {
              display: inline-flex;
              align-items: center;
              gap: 0.25rem;
              cursor: pointer;
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              color: var(--color-on-surface-variant);
            }
            .level-option input[type="radio"] {
              accent-color: var(--color-emerald-accent);
              cursor: pointer;
            }
            .level-option input[type="radio"]:checked + span {
              color: #047857; /* P1-1 : émeraude foncée ≈5.25:1 sur blanc */
              font-weight: 600;
            }
            /* Badge niveau dans le résultat (US-011) */
            .synthesis-level-badge {
              display: inline-block;
              font-size: 9px;
              letter-spacing: 0.08em;
              font-weight: 700;
              text-transform: uppercase;
              color: var(--color-on-primary);
              background: var(--color-emerald-accent);
              border-radius: 2px;
              padding: 1px 5px;
              margin-left: 0.375rem;
              vertical-align: middle;
            }
            </style>
            CSS_BLOCK;
    }

    /**
     * Réponse 200 "empty state" : table vide ou aucun brief ready (US-001 scénario erreur 1).
     */
    private function emptyStateResponse(): Response
    {
        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="fr">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>DAILY BRIEF — Briefly AI</title>
                {$this->designTokensCss()}
                {$this->pageCss()}
            </head>
            <body>
                <!-- P1-2 : skip-link -->
                <a href="#main-content" class="skip-link">Aller au contenu principal</a>
                <header class="site-header" role="banner">
                    <nav class="nav-container" aria-label="Navigation principale">
                        <a href="/brief" class="logo" aria-label="Briefly AI — accueil">BRIEFLY</a>
                    </nav>
                </header>
                <main class="main-content" id="main-content">
                    <div class="brief-container">
                        <div class="brief-header">
                            <h1 class="brief-title">DAILY BRIEF</h1>
                        </div>
                        <div class="empty-state" role="status" aria-live="polite">
                            <p>Brief en cours de préparation — revenez dans quelques instants.</p>
                        </div>
                    </div>
                </main>
            </body>
            </html>
            HTML;

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Réponse 503 générique sans stacktrace (US-001 scénario erreur 2 + OWASP #7).
     *
     * Header Retry-After: 60 indique au client de réessayer dans 60 secondes.
     */
    private function serviceUnavailableResponse(): Response
    {
        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="fr">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Service indisponible — Briefly AI</title>
                {$this->designTokensCss()}
                {$this->pageCss()}
            </head>
            <body>
                <!-- P1-2 : skip-link -->
                <a href="#main-content" class="skip-link">Aller au contenu principal</a>
                <header class="site-header" role="banner">
                    <nav class="nav-container">
                        <a href="/brief" class="logo">BRIEFLY</a>
                    </nav>
                </header>
                <main class="main-content" id="main-content">
                    <div class="brief-container">
                        <div class="error-state" role="alert">
                            <h1>Service temporairement indisponible</h1>
                            <p>Nous rencontrons des difficultés techniques. Veuillez réessayer dans quelques instants.</p>
                            <a href="/brief">Actualiser la page</a>
                        </div>
                    </div>
                </main>
            </body>
            </html>
            HTML;

        return new Response(
            $html,
            Response::HTTP_SERVICE_UNAVAILABLE,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Retry-After' => '60',
            ],
        );
    }

    /**
     * Design tokens CSS (variables issues de design-tokens.css — INV-7).
     *
     * Inline Sprint 1 (Twig non installé).
     * À remplacer par <link href="/build/design-tokens.css"> quand Encore/Vite sera configuré.
     */
    private function designTokensCss(): string
    {
        return <<<'CSS_BLOCK'
            <style>
            :root {
              --color-emerald-accent: #10B981;
              --color-deep-indigo: #1E1B4B;
              --color-slate-gray: #64748B;
              --color-primary: #091426;
              --color-on-primary: #FFFFFF;
              --color-primary-container: #1E293B;
              --color-surface: #F7F9FB;
              --color-surface-card: #FFFFFF;
              --color-surface-border: #E2E8F0;
              --color-on-surface: #191C1E;
              --color-on-surface-variant: #45474C;
              --color-outline: #75777D;
              --font-headline: "Source Serif 4", Georgia, "Times New Roman", serif;
              --font-body: "Inter", system-ui, -apple-system, sans-serif;
              --font-meta: "Hanken Grotesk", ui-sans-serif, system-ui, sans-serif;
              --fs-headline-xl: 40px; --lh-headline-xl: 48px; --ls-headline: -0.02em;
              --fs-headline-xl-mobile: 30px; --lh-headline-xl-mobile: 36px;
              --fs-body-md: 16px; --lh-body-md: 24px;
              --fs-label: 12px; --lh-label: 16px; --ls-label: 0.05em;
              --radius: 0.25rem;
              --space-stack-sm: 0.5rem; --space-stack-md: 1.5rem; --space-stack-lg: 3rem;
              --space-gutter: 1.5rem;
              --read-max: 768px; --browse-max: 1120px;
            }
            @media (prefers-color-scheme: dark) {
              :root:not([data-theme="light"]) {
                --color-primary: #4EDEA3;
                --color-surface: #051424;
                --color-surface-card: #122131;
                --color-surface-border: #273647;
                --color-on-surface: #D4E4FA;
                --color-on-surface-variant: #BBCABF;
              }
            }
            </style>
            CSS_BLOCK;
    }

    /**
     * CSS de la barre de progression de lecture (US-007).
     *
     * Barre fixée en haut du viewport (position: fixed, top: 0), hauteur 2px,
     * couleur émeraude via token CSS (INV-2 — accent émeraude réservé à l'IA/progression).
     * Transition douce 0.1s pour un rendu fluide au scroll.
     * z-index: 100 — passe au-dessus du header (z-index 10) sans cacher le contenu.
     *
     * Dark mode : même couleur émeraude (token partagé, INV-2 respecté).
     */
    private function progressBarCss(): string
    {
        return <<<'CSS_BLOCK'
            <style>
            /* ── Barre de progression de lecture (US-007) ────────────────────────── */
            .progress-bar {
              position: fixed;
              top: 0;
              left: 0;
              height: 2px;
              width: 0%;
              background: var(--color-emerald-accent, #10B981);
              z-index: 100;
              transition: width 0.1s linear;
              /* ARIA progressbar : élément sémantique, couleur non exclusive (WCAG 2.1 AA) */
            }
            /* Dark mode : émeraude conservé (INV-2) */
            @media (prefers-color-scheme: dark) {
              :root:not([data-theme="light"]) .progress-bar {
                background: var(--color-emerald-accent, #10B981);
              }
            }
            </style>
            CSS_BLOCK;
    }

    /**
     * Script JS inline de la barre de progression de lecture (US-007).
     *
     * Implémente le comportement du Stimulus controller `progress-bar_controller.js`
     * en JavaScript vanilla, le temps que Symfony AssetMapper soit activé (Sprint 2+).
     * L'élément `data-controller="progress-bar"` sera alors pris en charge nativement
     * par le bundle Stimulus Bridge.
     *
     * Logique :
     * - Écoute scroll (passive) + throttle requestAnimationFrame (~50ms)
     * - pct = scrollTop / (scrollHeight - innerHeight) × 100, borné 0-100
     * - Cas division par zéro (page non-scrollable) → width = 100%
     * - Reset à 0% sur événement turbo:load (navigation Turbo Drive sans rechargement)
     * - Aucune erreur NaN/Infinity (WCAG 2.1 AA + scénario erreur 2 US-007)
     */
    private function progressBarJs(): string
    {
        return <<<'JS_BLOCK'
            <script>
            (function () {
              var bar = document.querySelector('[data-controller="progress-bar"]');
              if (!bar) return;

              var rafId = null;
              var lastRun = 0;
              var THROTTLE_MS = 50;

              function update() {
                var scrollTop = window.scrollY !== undefined ? window.scrollY : document.documentElement.scrollTop;
                var maxScroll = document.documentElement.scrollHeight - window.innerHeight;

                var pct;
                if (maxScroll <= 0) {
                  // Page non-scrollable : contenu tenant dans le viewport → 100%
                  pct = 100;
                } else {
                  pct = Math.round(Math.min(100, Math.max(0, (scrollTop / maxScroll) * 100)));
                }

                bar.style.width = pct + '%';
                bar.setAttribute('aria-valuenow', String(pct));
              }

              function onScroll() {
                var now = Date.now();
                if (now - lastRun < THROTTLE_MS) return;
                if (rafId !== null) return;
                rafId = requestAnimationFrame(function () {
                  rafId = null;
                  lastRun = Date.now();
                  update();
                });
              }

              function onTurboLoad() {
                bar.style.width = '0%';
                bar.setAttribute('aria-valuenow', '0');
              }

              // Calcul initial
              update();

              document.addEventListener('scroll', onScroll, { passive: true });
              document.addEventListener('turbo:load', onTurboLoad);
            })();
            </script>
            JS_BLOCK;
    }

    /**
     * CSS spécifique à la page brief.
     *
     * Responsive : 1 colonne mobile (< 768px), layout adaptatif desktop.
     * Invariant INV-2 : accent émeraude NON utilisé sur les cartes (réservé à l'IA).
     */
    private function pageCss(): string
    {
        return <<<'CSS_BLOCK'
            <style>
            /* P1-2 : skip-link (visuellement masqué, visible au focus) */
            .skip-link { position: absolute; top: -3rem; left: 0;
                background: var(--color-primary, #091426); color: #fff;
                padding: .5rem 1rem; z-index: 999; text-decoration: none;
                font-family: var(--font-meta); font-size: .875rem; font-weight: 600;
                border-radius: 0 0 4px 0; }
            .skip-link:focus { top: 0; }
            /* P1-5 : focus visible ≥2px sur tous les éléments interactifs */
            *:focus-visible { outline: 2px solid var(--color-primary, #091426); outline-offset: 2px; }
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            body {
              font-family: var(--font-body);
              background-color: var(--color-surface);
              color: var(--color-on-surface);
              line-height: var(--lh-body-md);
              min-height: 100vh;
            }
            .site-header {
              background-color: var(--color-primary-container);
              padding: 1rem var(--space-gutter);
              border-bottom: 1px solid var(--color-surface-border);
            }
            .nav-container {
              max-width: var(--browse-max);
              margin: 0 auto;
              display: flex;
              align-items: center;
            }
            .logo {
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              color: var(--color-on-primary);
              text-decoration: none;
              font-weight: 700;
            }
            .main-content {
              max-width: var(--browse-max);
              margin: 0 auto;
              padding: var(--space-stack-lg) var(--space-gutter);
            }
            .brief-container { max-width: var(--read-max); }
            .brief-header { margin-bottom: var(--space-stack-lg); }
            .brief-title {
              font-family: var(--font-meta);
              font-size: var(--fs-headline-xl);
              line-height: var(--lh-headline-xl);
              letter-spacing: var(--ls-headline);
              color: var(--color-on-surface);
              font-weight: 700;
            }
            @media (max-width: 767px) {
              .brief-title {
                font-size: var(--fs-headline-xl-mobile);
                line-height: var(--lh-headline-xl-mobile);
              }
            }
            .brief-timestamp {
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              color: var(--color-on-surface-variant);
              margin-top: var(--space-stack-sm);
              text-transform: uppercase;
            }
            .stories-list { list-style: none; display: flex; flex-direction: column; gap: var(--space-stack-md); }
            .story-card {
              background-color: var(--color-surface-card);
              border: 1px solid var(--color-surface-border);
              border-radius: var(--radius);
              padding: var(--space-stack-md);
              display: flex;
              gap: 1rem;
              position: relative;
            }
            .story-number {
              font-family: var(--font-meta);
              font-size: var(--fs-headline-xl);
              font-weight: 700;
              color: var(--color-surface-border);
              line-height: 1;
              flex-shrink: 0;
              min-width: 3rem;
            }
            .story-body { flex: 1; min-width: 0; }
            .story-title {
              font-family: var(--font-headline);
              font-size: 20px;
              font-weight: 600;
              color: var(--color-on-surface);
              margin-bottom: var(--space-stack-sm);
              line-height: 1.3;
            }
            .story-source {
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              color: var(--color-outline);
              text-transform: uppercase;
              margin-bottom: var(--space-stack-sm);
            }
            .story-excerpt {
              font-size: var(--fs-body-md);
              line-height: var(--lh-body-md);
              color: var(--color-on-surface-variant);
              margin-bottom: 1rem;
            }
            .story-link {
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              color: var(--color-slate-gray);
              text-decoration: none;
              font-weight: 600;
              text-transform: uppercase;
              border: 1px solid var(--color-surface-border);
              padding: 0.375rem 0.75rem;
              border-radius: var(--radius);
              display: inline-block;
              transition: border-color 0.15s ease;
            }
            .story-link:hover { border-color: var(--color-outline); color: var(--color-on-surface); }
            .empty-state, .error-state {
              background-color: var(--color-surface-card);
              border: 1px solid var(--color-surface-border);
              border-radius: var(--radius);
              padding: var(--space-stack-lg);
              text-align: center;
            }
            .error-state h1 { margin-bottom: var(--space-stack-md); font-size: 24px; }
            .error-state a {
              color: var(--color-slate-gray);
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              text-transform: uppercase;
            }
            .site-footer {
              padding: var(--space-stack-md) var(--space-gutter);
              text-align: center;
              font-size: var(--fs-label);
              color: var(--color-on-surface-variant);
              border-top: 1px solid var(--color-surface-border);
              margin-top: var(--space-stack-lg);
            }
            </style>
            CSS_BLOCK;
    }

    /**
     * CSS spécifique à la section Featured Summary (US-006).
     *
     * Responsive :
     * - MASQUÉE sur mobile (< 768px) — display:none
     * - Visible uniquement desktop (>= 768px)
     *
     * Invariant INV-2 : accent émeraude (#10B981) UNIQUEMENT sur le badge BRIEFLY AI.
     * Le fallback (.featured-summary--fallback) n'a PAS de bordure émeraude.
     *
     * CTA "Lire le brief complet" :
     * - Collé à droite dans la nav (flex, margin-left: auto)
     * - Masqué sur mobile (< 768px) — display:none
     */
    private function featuredSummaryCss(): string
    {
        return <<<'CSS_BLOCK'
            <style>
            /* ── Featured Summary (US-006) ────────────────────────────────── */
            /* Mobile : section masquée */
            .featured-summary {
              display: none;
            }
            /* Desktop : section visible */
            @media (min-width: 768px) {
              .featured-summary {
                display: block;
                background-color: var(--color-surface-card);
                border: 1px solid var(--color-emerald-accent);
                border-radius: var(--radius);
                padding: var(--space-stack-md);
                margin-bottom: var(--space-stack-md);
              }
              .featured-summary--fallback {
                border-color: var(--color-surface-border);
              }
            }
            .featured-summary__badge {
              display: flex;
              align-items: center;
              gap: 0.375rem;
              margin-bottom: var(--space-stack-sm);
            }
            .featured-summary__icon {
              color: var(--color-emerald-accent);
              font-size: 1rem;
              line-height: 1;
            }
            .featured-summary__badge-label {
              font-family: var(--font-meta);
              font-size: var(--fs-label);
              letter-spacing: var(--ls-label);
              color: #047857; /* P1-1 : émeraude foncée ≈5.25:1 sur blanc */
              font-weight: 700;
              text-transform: uppercase;
            }
            .featured-summary__text {
              font-size: var(--fs-body-md);
              line-height: var(--lh-body-md);
              color: var(--color-on-surface);
            }
            /* ── CTA "Lire le brief complet" (nav sticky, desktop uniquement) */
            .cta-read-brief {
              display: none;
            }
            @media (min-width: 768px) {
              /* P1-1 : CTA navigation NON-IA → couleur primaire (INV-2 : émeraude réservée à l'IA) */
              .cta-read-brief {
                display: inline-block;
                margin-left: auto;
                font-family: var(--font-meta);
                font-size: var(--fs-label);
                letter-spacing: var(--ls-label);
                color: var(--color-on-primary);
                background-color: var(--color-primary);
                text-decoration: none;
                font-weight: 700;
                text-transform: uppercase;
                padding: 0.375rem 0.875rem;
                border-radius: var(--radius);
                transition: opacity 0.15s ease;
              }
              .cta-read-brief:hover {
                opacity: 0.88;
              }
            }
            </style>
            CSS_BLOCK;
    }
}
