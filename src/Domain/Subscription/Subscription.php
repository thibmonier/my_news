<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Subscription aggregate root — PHP pur, sans framework.
 *
 * Invariants :
 *  - isActive() = status ACTIVE && currentPeriodEnd > now()
 *  - stripeEventId sert de clé d'idempotence (UNIQUE en base)
 */
final class Subscription
{
    public function __construct(
        private readonly string $id,
        private readonly string $userId,
        private readonly string $stripeCustomerId,
        private readonly string $stripeSubscriptionId,
        private readonly SubscriptionPlan $plan,
        private readonly SubscriptionStatus $status,
        private readonly \DateTimeImmutable $currentPeriodEnd,
        private readonly string $stripeEventId,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getStripeCustomerId(): string
    {
        return $this->stripeCustomerId;
    }

    public function getStripeSubscriptionId(): string
    {
        return $this->stripeSubscriptionId;
    }

    public function getPlan(): SubscriptionPlan
    {
        return $this->plan;
    }

    public function getStatus(): SubscriptionStatus
    {
        return $this->status;
    }

    public function getCurrentPeriodEnd(): \DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function getStripeEventId(): string
    {
        return $this->stripeEventId;
    }

    public function isActive(): bool
    {
        return SubscriptionStatus::ACTIVE === $this->status
            && $this->currentPeriodEnd > new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
