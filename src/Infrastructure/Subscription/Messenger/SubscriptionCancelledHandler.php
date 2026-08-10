<?php

declare(strict_types=1);

namespace App\Infrastructure\Subscription\Messenger;

use App\Application\Subscription\StripeWebhookMessage;
use App\Infrastructure\Subscription\Persistence\DoctrineSubscriptionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Traite l'événement Stripe 'customer.subscription.deleted'.
 *
 * Met subscriptions.status = 'cancelled' via updateByStripeSubscriptionId().
 * Idempotence : stripe_subscription_id inconnu → log WARNING skip-not-found + return (0 erreur).
 * Le webhook controller retourne HTTP 200 systématiquement — le handler ne doit jamais lever.
 *
 * SÉCURITÉ : payload jamais dans les logs. UUID uniquement.
 */
#[AsMessageHandler]
final class SubscriptionCancelledHandler
{
    public function __construct(
        private readonly DoctrineSubscriptionRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(StripeWebhookMessage $message): void
    {
        if ('customer.subscription.deleted' !== $message->eventType) {
            return;
        }

        $payload = $message->payload;

        $stripeSubscriptionId = isset($payload['id']) && \is_string($payload['id'])
            ? $payload['id'] : '';

        if ('' === $stripeSubscriptionId) {
            return;
        }

        $found = $this->repository->updateByStripeSubscriptionId($stripeSubscriptionId, [
            'status' => 'cancelled',
        ]);

        if (!$found) {
            $this->logger->warning('SubscriptionCancelledHandler: stripe_subscription_id not found', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'action' => 'skip-not-found',
            ]);
        }
    }
}
