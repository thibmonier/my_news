<?php

declare(strict_types=1);

namespace Tests\Stub;

use App\Domain\Subscription\StripeGatewayInterface;

/**
 * Stub Stripe Gateway pour les tests d'intégration.
 * Retourne une URL de test sans appeler l'API Stripe réelle.
 *
 * Enregistré comme alias de StripeGatewayInterface en APP_ENV=test via services_test.yaml.
 */
final class StripeGatewayStub implements StripeGatewayInterface
{
    public const TEST_CHECKOUT_URL = 'https://checkout.stripe.com/c/pay/test_stub_session';

    public function createCheckoutSession(
        string $plan,
        string $customerEmail,
        string $successUrl,
        string $cancelUrl,
    ): string {
        return self::TEST_CHECKOUT_URL . '_' . $plan;
    }
}
