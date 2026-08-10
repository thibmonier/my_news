<?php

declare(strict_types=1);

namespace App\Infrastructure\Subscription\Stripe;

use App\Domain\Subscription\StripeGatewayInterface;

/**
 * Adapter Stripe SDK pour la création de sessions Checkout.
 *
 * Secrets injectés via %env(STRIPE_*)% — jamais en dur ni dans les logs.
 * Stripe Tax activé (automatic_tax: enabled) pour calcul TVA automatique.
 */
final class StripeCheckoutGateway implements StripeGatewayInterface
{
    public function __construct(
        private readonly string $stripeSecretKey,
        private readonly string $stripePriceMonthly,
        private readonly string $stripePriceYearly,
    ) {
    }

    public function createCheckoutSession(
        string $plan,
        string $customerEmail,
        string $successUrl,
        string $cancelUrl,
    ): string {
        \Stripe\Stripe::setApiKey($this->stripeSecretKey);

        $priceId = 'monthly' === $plan ? $this->stripePriceMonthly : $this->stripePriceYearly;

        $session = \Stripe\Checkout\Session::create([
            'mode' => 'subscription',
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'customer_email' => $customerEmail,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'automatic_tax' => ['enabled' => true],
        ]);

        return (string) $session->url;
    }

    /**
     * Crée une session Stripe Customer Portal.
     * La session expire après 5 minutes (comportement Stripe natif).
     */
    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        \Stripe\Stripe::setApiKey($this->stripeSecretKey);

        $session = \Stripe\BillingPortal\Session::create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        /** @var string $url */
        $url = $session->url;

        return $url;
    }
}
