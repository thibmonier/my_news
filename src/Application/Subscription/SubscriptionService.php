<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Domain\Subscription\StripeGatewayInterface;
use App\Domain\Subscription\SubscriptionRepositoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Service applicatif — orchestration de l'abonnement Premium.
 *
 * createCheckoutSession() délègue la création Stripe au port StripeGatewayInterface
 * (mockable dans les tests sans accès à l'API Stripe).
 *
 * Les Price IDs sont injectés via le gateway — jamais référencés directement ici.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly StripeGatewayInterface $stripeGateway,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Crée une session Stripe Checkout et retourne l'URL de redirection.
     *
     * @param string $plan 'monthly' | 'yearly'
     * @param string $userEmail email de l'utilisateur authentifié (pré-remplissage Checkout)
     */
    public function createCheckoutSession(string $userEmail, string $plan): string
    {
        $successUrl = $this->urlGenerator->generate(
            'app_dashboard',
            ['checkout' => 'success'],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $cancelUrl = $this->urlGenerator->generate(
            'premium_index',
            ['checkout' => 'cancelled'],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->stripeGateway->createCheckoutSession(
            plan: $plan,
            customerEmail: $userEmail,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
        );
    }

    public function isPremium(string $userUuid): bool
    {
        return $this->subscriptionRepository->isPremium($userUuid);
    }
}
