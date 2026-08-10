<?php

declare(strict_types=1);

namespace App\Infrastructure\Subscription\Messenger;

use App\Application\Subscription\StripeWebhookMessage;
use App\Infrastructure\Subscription\Persistence\DoctrineSubscriptionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Traite l'événement Stripe 'invoice.payment_failed'.
 *
 * Met subscriptions.status = 'past_due' pour activer la grace period (3 jours).
 * L'accès Premium est maintenu tant que current_period_end > NOW() (QuotaService::isPremium).
 * Idempotence : stripe_subscription_id inconnu → log WARNING + return (0 erreur).
 *
 * SÉCURITÉ : log WARNING avec UUID uniquement — jamais d'email ni de données client.
 */
#[AsMessageHandler]
final class PaymentFailedHandler
{
    public function __construct(
        private readonly DoctrineSubscriptionRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(StripeWebhookMessage $message): void
    {
        if ('invoice.payment_failed' !== $message->eventType) {
            return;
        }

        $payload = $message->payload;

        // invoice.payment_failed contient subscription (id de l'abonnement)
        $stripeSubscriptionId = isset($payload['subscription']) && \is_string($payload['subscription'])
            ? $payload['subscription'] : '';

        if ('' === $stripeSubscriptionId) {
            return;
        }

        $found = $this->repository->updateByStripeSubscriptionId($stripeSubscriptionId, [
            'status' => 'past_due',
        ]);

        if (!$found) {
            $this->logger->warning('PaymentFailedHandler: stripe_subscription_id not found', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'action' => 'skip-not-found',
            ]);

            return;
        }

        // Log UUID uniquement — jamais d'email ni de données de paiement
        $this->logger->warning('PaymentFailedHandler: subscription past_due', [
            'stripe_subscription_id' => $stripeSubscriptionId,
            'event_id' => $message->eventId,
            'action' => 'past_due',
        ]);
    }
}
