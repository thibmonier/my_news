<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur — Paywall modal Premium (US-013 T-013-08).
 *
 * Route : GET /quota/paywall-modal
 * Auth  : ROLE_USER requis
 *
 * Retourne un fragment HTML (Turbo Frame `paywall-modal`) affiché
 * dans la page lors d'une réponse HTTP 402 (quota épuisé).
 *
 * US-013 (Sprint 4) :
 * - Le CTA "Passer à Briefly Premium — 12€/mois" est actif (lien Stripe)
 * - Route premium_checkout (US-034a) activée
 * - Template migré vers templates/quota/paywall_modal.html.twig
 *
 * Remplace le placeholder US-033 (CTA désactivé, href="#").
 *
 * Couche Presentation.
 */
#[Route('/quota/paywall-modal', name: 'quota_paywall_modal', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class PaywallModalController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('quota/paywall_modal.html.twig');
    }
}
