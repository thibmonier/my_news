<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Domain\Subscription\StripeGatewayInterface;
use App\Domain\Subscription\Subscription;
use App\Domain\Subscription\SubscriptionNotFoundException;
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

    /**
     * Crée une session Stripe Customer Portal pour l'utilisateur authentifié.
     *
     * @throws SubscriptionNotFoundException si aucun abonnement en base pour cet utilisateur
     */
    public function createPortalSession(string $userUuid): string
    {
        $subscription = $this->subscriptionRepository->findByUserId($userUuid);

        if (null === $subscription) {
            throw SubscriptionNotFoundException::forUser($userUuid);
        }

        $returnUrl = $this->urlGenerator->generate(
            'app_profile_edit',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->stripeGateway->createPortalSession(
            customerId: $subscription->getStripeCustomerId(),
            returnUrl: $returnUrl,
        );
    }

    /**
     * Retourne l'abonnement d'un utilisateur (quel que soit le statut) ou null.
     * Utilisé par le template de profil pour afficher le statut d'abonnement.
     */
    public function findSubscriptionForUser(string $userUuid): ?Subscription
    {
        return $this->subscriptionRepository->findByUserId($userUuid);
    }

    public function isPremium(string $userUuid): bool
    {
        return $this->subscriptionRepository->isPremium($userUuid);
    }
}
