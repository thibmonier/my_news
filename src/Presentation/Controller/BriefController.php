<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Brief\FeaturedSummary\FeaturedSummaryServiceInterface;
use App\Application\Summary\ArticleSummaryServiceInterface;
use App\Domain\Brief\BriefPublicViewRepositoryInterface;
use App\Presentation\ViewModel\DailyBriefViewModel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 * - Brief disponible    → 200 + brief/index.html.twig (Featured Summary + 3 histoires + condensés IA)
 * - Table vide          → 200 + brief/empty.html.twig ("Brief en cours de préparation")
 * - Erreur base données → 503 + brief/error.html.twig + header Retry-After: 60
 *
 * US-004 — Condensé IA par article :
 * - Appel à ArticleSummaryService pour chaque histoire avant le rendu Twig
 * - Badge "BRIEFLY AI:" accent émeraude — rendu via brief/index.html.twig
 * - Mode dégradé : badge "RÉSUMÉ AUTOMATIQUE INDISPONIBLE" si LLM indispo
 *
 * US-006 — Featured Summary desktop :
 * - Section narrative en tête du /brief (badge BRIEFLY AI: émeraude)
 * - Masquée sur mobile (< 768px) via CSS inline dans le template
 * - CTA sticky "Lire le brief complet" → ancre #brief-stories (même page)
 * - Fallback : texte générique sans badge émeraude si Mistral KO
 *
 * SÉCURITÉ OWASP #7 (Mishandling Exceptional Conditions) :
 * - Jamais de stacktrace dans la réponse HTML
 * - Messages d'erreur génériques côté client
 * - Logging côté serveur sans données personnelles
 *
 * XSS : Twig auto-escape (htmlspecialchars équivalent) sur toutes les variables.
 * RGPD : aucun PII dans les variables passées au template (US-004 T-004-11).
 *
 * Couche Presentation — dépend de Domain + Application.
 * Deptrac : Presentation:[Domain, Application].
 */
final class BriefController extends AbstractController
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

        return $this->render('brief/index.html.twig', [
            'viewModel' => $viewModel,
            'featuredSummary' => $featuredSummary,
        ]);
    }

    /**
     * Page d'accueil — redirect SEO 301 vers /brief (US-001 conversation §1).
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): RedirectResponse
    {
        return new RedirectResponse('/brief', Response::HTTP_MOVED_PERMANENTLY);
    }

    // ── Réponses d'état ────────────────────────────────────────────────────────

    /**
     * Réponse 200 "empty state" : table vide ou aucun brief ready (US-001 scénario erreur 1).
     */
    private function emptyStateResponse(): Response
    {
        return $this->render('brief/empty.html.twig');
    }

    /**
     * Réponse 503 générique sans stacktrace (US-001 scénario erreur 2 + OWASP #7).
     *
     * Header Retry-After: 60 indique au client de réessayer dans 60 secondes.
     */
    private function serviceUnavailableResponse(): Response
    {
        $response = new Response(
            '',
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Retry-After' => '60'],
        );

        return $this->render('brief/error.html.twig', [], $response);
    }
}
