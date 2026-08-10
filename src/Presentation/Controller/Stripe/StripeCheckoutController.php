<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Stripe;

use App\Application\Subscription\SubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur Stripe Checkout — page Premium et initiation de session de paiement.
 *
 * Routes utilisées par US-013 T-013-08 (liens CTA depuis le paywall).
 * Toutes les routes nécessitent ROLE_USER (Stripe Checkout est un flux authentifié).
 */
#[IsGranted('ROLE_USER')]
final class StripeCheckoutController extends AbstractController
{
    private const VALID_PLANS = ['monthly', 'yearly'];

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    #[Route('/premium', name: 'premium_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('premium/index.html.twig');
    }

    /**
     * Initie une session Stripe Checkout pour le plan donné.
     *
     * Retourne HTTP 400 si le plan est invalide (protection contre les paramètres arbitraires).
     * Retourne HTTP 302 vers l'URL Stripe Checkout en cas de succès.
     *
     * @param string $plan 'monthly' | 'yearly'
     */
    #[Route('/premium/checkout/{plan}', name: 'premium_checkout', methods: ['GET'])]
    public function checkout(string $plan, #[CurrentUser] UserInterface $user): Response
    {
        if (!\in_array($plan, self::VALID_PLANS, true)) {
            return new Response('Plan invalide. Plans acceptés : monthly, yearly.', Response::HTTP_BAD_REQUEST);
        }

        $checkoutUrl = $this->subscriptionService->createCheckoutSession(
            userEmail: $user->getUserIdentifier(),
            plan: $plan,
        );

        return $this->redirect($checkoutUrl, Response::HTTP_FOUND);
    }
}
