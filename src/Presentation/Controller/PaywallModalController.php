<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur — Paywall placeholder (US-033 Sprint 1).
 *
 * Route : GET /quota/paywall-modal
 * Auth  : ROLE_USER requis
 *
 * Retourne un fragment HTML (Turbo Frame `paywall-modal`) affiché
 * dans le Stimulus controller `synthesis` lors d'une réponse HTTP 429.
 *
 * Sprint 1 (placeholder) :
 * - Le CTA "Passer à Briefly Premium — 12€/mois" est rendu `disabled`
 * - Stripe Billing sera implémenté en US-034
 *
 * Note : HTML inline (Twig non installé en Sprint 1).
 * À migrer vers templates/quota/paywall_modal.html.twig quand symfony/twig-bundle
 * sera ajouté.
 *
 * Couche Presentation (dépend uniquement de l'infrastructure Symfony HTTP).
 */
#[Route('/quota/paywall-modal', name: 'quota_paywall_modal', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class PaywallModalController
{
    public function __invoke(): Response
    {
        return new Response(
            content: $this->renderPaywallModal(),
            status: Response::HTTP_OK,
            headers: ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * Génère le fragment HTML du paywall modal (Turbo Frame).
     *
     * Sprint 1 : CTA désactivé (`disabled` + `aria-disabled="true"`).
     * US-034 activera le lien vers Stripe Checkout.
     */
    private function renderPaywallModal(): string
    {
        return <<<'HTML'
            <turbo-frame id="paywall-modal">
                <div
                    class="paywall-modal-overlay"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="paywall-title"
                    aria-describedby="paywall-desc"
                >
                    <div class="paywall-modal-content">
                        <h2 id="paywall-title">Limite atteinte</h2>
                        <p id="paywall-desc">
                            Vous avez utilisé vos <strong>3 synthèses gratuites aujourd'hui</strong>.
                            Votre quota se renouvelle automatiquement à minuit (UTC).
                        </p>

                        <div class="paywall-premium-cta">
                            <p class="paywall-premium-tagline">
                                Accédez à des synthèses IA illimitées avec <strong>Briefly Premium</strong>.
                            </p>
                            <p class="paywall-premium-price">À partir de <strong>12€/mois</strong></p>

                            <!--
                                Sprint 1 : CTA désactivé (placeholder).
                                US-034 : remplacer href="#" par l'URL Stripe Checkout.
                            -->
                            <a
                                href="#"
                                class="paywall-btn-premium"
                                aria-disabled="true"
                                tabindex="-1"
                                title="Disponible prochainement"
                            >
                                Passer à Briefly Premium — 12€/mois
                            </a>

                            <p class="paywall-sprint-note">
                                <em>Abonnement Premium bientôt disponible.</em>
                            </p>
                        </div>

                        <button
                            type="button"
                            class="paywall-btn-close"
                            data-action="click->synthesis#closePaywall"
                            aria-label="Fermer"
                        >
                            ✕ Fermer
                        </button>
                    </div>
                </div>

                <style>
                    .paywall-modal-overlay {
                        position: fixed; inset: 0; background: rgba(0,0,0,.6);
                        display: flex; align-items: center; justify-content: center;
                        z-index: 1000;
                    }
                    .paywall-modal-content {
                        background: #fff; border-radius: 8px; padding: 2rem;
                        max-width: 460px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,.3);
                    }
                    .paywall-modal-content h2 { margin: 0 0 .75rem; color: #111827; font-size: 1.4rem; }
                    .paywall-modal-content p  { color: #374151; line-height: 1.6; }
                    .paywall-premium-cta { margin-top: 1.5rem; padding: 1.25rem;
                        background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; }
                    .paywall-premium-tagline { font-weight: 600; color: #065f46; }
                    .paywall-premium-price   { font-size: 1.1rem; color: #047857; margin: .5rem 0; }
                    .paywall-btn-premium {
                        display: block; margin-top: 1rem; padding: .875rem 1.5rem;
                        background: #d1d5db; color: #6b7280;
                        border-radius: 6px; text-align: center; text-decoration: none;
                        font-weight: 600; cursor: not-allowed; pointer-events: none;
                    }
                    .paywall-sprint-note { font-size: .75rem; color: #9ca3af; margin-top: .5rem; }
                    .paywall-btn-close {
                        margin-top: 1.25rem; background: none; border: 1px solid #d1d5db;
                        padding: .5rem 1rem; border-radius: 4px; cursor: pointer; color: #6b7280;
                    }
                    .paywall-btn-close:hover { background: #f9fafb; }
                </style>
            </turbo-frame>
            HTML;
    }
}
