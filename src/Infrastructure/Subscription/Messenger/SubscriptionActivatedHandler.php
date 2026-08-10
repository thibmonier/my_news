<?php

declare(strict_types=1);

namespace App\Infrastructure\Subscription\Messenger;

use App\Application\Subscription\StripeWebhookMessage;
use App\Domain\Subscription\Subscription;
use App\Domain\Subscription\SubscriptionPlan;
use App\Domain\Subscription\SubscriptionRepositoryInterface;
use App\Domain\Subscription\SubscriptionStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Handler Messenger pour l'activation des abonnements Premium.
 *
 * Traite uniquement 'checkout.session.completed' — les autres types sont ignorés silencieusement.
 * Idempotence garantie par INSERT ... ON CONFLICT (stripe_event_id) DO NOTHING en base.
 *
 * SÉCURITÉ : le payload Stripe ne doit JAMAIS apparaître dans les logs.
 */
#[AsMessageHandler]
final class SubscriptionActivatedHandler
{
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(StripeWebhookMessage $message): void
    {
        if ('checkout.session.completed' !== $message->eventType) {
            return;
        }

        $payload = $message->payload;

        $stripeCustomerId = isset($payload['customer']) && \is_string($payload['customer'])
            ? $payload['customer'] : '';

        $stripeSubscriptionId = isset($payload['subscription']) && \is_string($payload['subscription'])
            ? $payload['subscription'] : '';

        // Extraire le plan depuis les metadata ou les line_items
        $plan = $this->resolvePlan($payload);

        // current_period_end : +30 jours par défaut (Stripe l'envoie séparément via subscription.created)
        $currentPeriodEnd = new \DateTimeImmutable(
            'monthly' === $plan->value ? '+30 days' : '+365 days',
            new \DateTimeZone('UTC'),
        );

        // user_id : transmis via client_reference_id ou metadata.user_id
        $userId = isset($payload['client_reference_id']) && \is_string($payload['client_reference_id'])
            ? $payload['client_reference_id']
            : '';

        if ('' === $userId) {
            /** @var array<string, mixed> $metadata */
            $metadata = isset($payload['metadata']) && \is_array($payload['metadata'])
                ? $payload['metadata'] : [];
            $userId = isset($metadata['user_id']) && \is_string($metadata['user_id'])
                ? $metadata['user_id'] : '';
        }

        $subscription = new Subscription(
            id: Uuid::v4()->toRfc4122(),
            userId: $userId,
            stripeCustomerId: $stripeCustomerId,
            stripeSubscriptionId: $stripeSubscriptionId,
            plan: $plan,
            status: SubscriptionStatus::ACTIVE,
            currentPeriodEnd: $currentPeriodEnd,
            stripeEventId: $message->eventId,
        );

        // INSERT ... ON CONFLICT (stripe_event_id) DO NOTHING (idempotence)
        $this->subscriptionRepository->save($subscription);

        $this->logger->info('Subscription activated', [
            'event_id' => $message->eventId,
            'plan' => $plan->value,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolvePlan(array $payload): SubscriptionPlan
    {
        // Priorité 1 : metadata.plan
        /** @var array<string, mixed> $metadata */
        $metadata = isset($payload['metadata']) && \is_array($payload['metadata'])
            ? $payload['metadata'] : [];

        if (isset($metadata['plan']) && \is_string($metadata['plan'])) {
            $plan = SubscriptionPlan::tryFrom($metadata['plan']);
            if (null !== $plan) {
                return $plan;
            }
        }

        // Priorité 2 : display_items[0].plan.interval
        /** @var array<int, mixed> $displayItems */
        $displayItems = isset($payload['display_items']) && \is_array($payload['display_items'])
            ? $payload['display_items'] : [];

        if (isset($displayItems[0]) && \is_array($displayItems[0])) {
            /** @var array<string, mixed> $item */
            $item = $displayItems[0];
            /** @var array<string, mixed> $planInfo */
            $planInfo = isset($item['plan']) && \is_array($item['plan']) ? $item['plan'] : [];
            if (isset($planInfo['interval']) && 'year' === $planInfo['interval']) {
                return SubscriptionPlan::YEARLY;
            }
        }

        return SubscriptionPlan::MONTHLY;
    }
}
