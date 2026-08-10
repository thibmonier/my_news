<?php

declare(strict_types=1);

namespace App\Infrastructure\Subscription\Messenger;

use App\Application\Subscription\StripeWebhookMessage;
use App\Infrastructure\Subscription\Persistence\DoctrineSubscriptionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Traite l'événement Stripe 'customer.subscription.updated'.
 *
 * Met à jour status, plan, current_period_end et cancel_at_period_end en base.
 * Idempotence : stripe_subscription_id inconnu → log WARNING + return (0 erreur).
 *
 * SÉCURITÉ : payload jamais dans les logs. UUID uniquement.
 */
#[AsMessageHandler]
final class SubscriptionUpdatedHandler
{
    public function __construct(
        private readonly DoctrineSubscriptionRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(StripeWebhookMessage $message): void
    {
        if ('customer.subscription.updated' !== $message->eventType) {
            return;
        }

        $payload = $message->payload;

        $stripeSubscriptionId = isset($payload['id']) && \is_string($payload['id'])
            ? $payload['id'] : '';

        if ('' === $stripeSubscriptionId) {
            return;
        }

        $fields = [];

        if (isset($payload['status']) && \is_string($payload['status'])) {
            $fields['status'] = $payload['status'];
        }

        if (isset($payload['plan']) && \is_array($payload['plan']) && isset($payload['plan']['interval'])) {
            $fields['plan'] = 'year' === $payload['plan']['interval'] ? 'yearly' : 'monthly';
        }

        if (isset($payload['current_period_end']) && \is_int($payload['current_period_end'])) {
            $fields['current_period_end'] = (new \DateTimeImmutable('@' . $payload['current_period_end']))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:sO');
        }

        if (isset($payload['cancel_at_period_end']) && \is_bool($payload['cancel_at_period_end'])) {
            $fields['cancel_at_period_end'] = $payload['cancel_at_period_end'] ? 'true' : 'false';
        }

        $found = $this->repository->updateByStripeSubscriptionId($stripeSubscriptionId, $fields);

        if (!$found) {
            $this->logger->warning('SubscriptionUpdatedHandler: stripe_subscription_id not found', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'action' => 'skip-not-found',
            ]);
        }
    }
}
