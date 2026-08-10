<?php

declare(strict_types=1);

namespace App\Infrastructure\Subscription\Persistence;

use App\Domain\Subscription\Subscription;
use App\Domain\Subscription\SubscriptionRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Adapter Doctrine pour la persistance des abonnements Premium.
 *
 * isPremium() utilise l'index composé (status, current_period_end) pour les performances.
 * save() délègue à DBAL pour le INSERT ... ON CONFLICT DO NOTHING (idempotence webhook).
 */
class DoctrineSubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Persiste un abonnement via INSERT ... ON CONFLICT (stripe_event_id) DO NOTHING.
     * Garantit l'idempotence : un doublon stripe_event_id est ignoré silencieusement.
     */
    public function save(Subscription $subscription): void
    {
        $periodEnd = $subscription->getCurrentPeriodEnd();

        $this->connection->executeStatement(
            'INSERT INTO subscriptions
                (id, user_id, stripe_customer_id, stripe_subscription_id, plan, status, current_period_end, stripe_event_id, created_at)
             VALUES
                (:id, :userId, :stripeCustomerId, :stripeSubscriptionId, :plan, :status, :currentPeriodEnd, :stripeEventId, :createdAt)
             ON CONFLICT (stripe_event_id) DO NOTHING',
            [
                'id' => $subscription->getId(),
                'userId' => $subscription->getUserId(),
                'stripeCustomerId' => $subscription->getStripeCustomerId(),
                'stripeSubscriptionId' => $subscription->getStripeSubscriptionId(),
                'plan' => $subscription->getPlan()->value,
                'status' => $subscription->getStatus()->value,
                'currentPeriodEnd' => $periodEnd->format('Y-m-d H:i:sO'),
                'stripeEventId' => $subscription->getStripeEventId(),
                'createdAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sO'),
            ],
        );
    }

    /**
     * Vérifie si l'utilisateur a un accès Premium.
     * Grace period : status = 'past_due' conserve l'accès tant que current_period_end > NOW().
     * Utilise l'index composé (status, current_period_end) pour les performances.
     */
    public function isPremium(string $userUuid): bool
    {
        $result = $this->connection->fetchOne(
            "SELECT 1 FROM subscriptions
             WHERE user_id = :userId
               AND status IN ('active', 'past_due')
               AND current_period_end > NOW()
             LIMIT 1",
            ['userId' => $userUuid],
        );

        return false !== $result;
    }

    public function findByStripeEventId(string $eventId): ?Subscription
    {
        $entity = $this->entityManager
            ->getRepository(DoctrineSubscriptionEntity::class)
            ->findOneBy(['stripeEventId' => $eventId]);

        return $entity?->toDomain();
    }

    public function findByStripeSubscriptionId(string $subscriptionId): ?Subscription
    {
        $entity = $this->entityManager
            ->getRepository(DoctrineSubscriptionEntity::class)
            ->findOneBy(['stripeSubscriptionId' => $subscriptionId]);

        return $entity?->toDomain();
    }

    public function findByStripeCustomerId(string $customerId): ?Subscription
    {
        $entity = $this->entityManager
            ->getRepository(DoctrineSubscriptionEntity::class)
            ->findOneBy(['stripeCustomerId' => $customerId]);

        return $entity?->toDomain();
    }

    public function findByUserId(string $userUuid): ?Subscription
    {
        $entity = $this->entityManager
            ->getRepository(DoctrineSubscriptionEntity::class)
            ->findOneBy(['userId' => $userUuid]);

        return $entity?->toDomain();
    }

    /**
     * Met à jour un abonnement par stripe_subscription_id.
     * Retourne false si aucun enregistrement correspondant n'est trouvé.
     *
     * Champs autorisés : status, plan, current_period_end, cancel_at_period_end.
     * Appelé depuis les handlers Infrastructure (pas exposé dans le port Domain).
     *
     * @param array<string, mixed> $fields
     */
    public function updateByStripeSubscriptionId(string $stripeSubscriptionId, array $fields): bool
    {
        $allowed = ['status', 'plan', 'current_period_end', 'cancel_at_period_end'];
        $setClauses = [];
        $params = ['stripeSubscriptionId' => $stripeSubscriptionId];

        foreach ($fields as $field => $value) {
            if (!\in_array($field, $allowed, true)) {
                continue;
            }
            $setClauses[] = "{$field} = :{$field}";
            $params[$field] = $value;
        }

        if ([] === $setClauses) {
            return false;
        }

        $affected = $this->connection->executeStatement(
            'UPDATE subscriptions SET ' . implode(', ', $setClauses) . ' WHERE stripe_subscription_id = :stripeSubscriptionId',
            $params,
        );

        return $affected > 0;
    }
}
