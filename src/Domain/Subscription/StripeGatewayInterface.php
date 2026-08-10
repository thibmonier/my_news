<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Port primaire — création d'une session Stripe Checkout.
 *
 * Le port est dans le Domain pour permettre le mock dans les tests sans dépendre
 * du SDK Stripe (qui ne doit apparaître qu'en Infrastructure).
 */
interface StripeGatewayInterface
{
    /**
     * Crée une session Stripe Checkout et retourne l'URL de redirection.
     *
     * @param string $plan 'monthly' | 'yearly'
     * @param string $customerEmail email de l'acheteur (pour pré-remplissage Checkout)
     * @param string $successUrl URL de retour après paiement réussi
     * @param string $cancelUrl URL de retour après annulation
     */
    public function createCheckoutSession(
        string $plan,
        string $customerEmail,
        string $successUrl,
        string $cancelUrl,
    ): string;

    /**
     * Crée une session Stripe Customer Portal et retourne l'URL de redirection.
     *
     * @param string $customerId stripe_customer_id depuis la table subscriptions
     * @param string $returnUrl URL de retour après fermeture du portail (ex: /profile/edit)
     */
    public function createPortalSession(string $customerId, string $returnUrl): string;
}
