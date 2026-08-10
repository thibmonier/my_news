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
        private readonly bool $cancelAtPeriodEnd = false,
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

    public function getCancelAtPeriodEnd(): bool
    {
        return $this->cancelAtPeriodEnd;
    }

    public function isActive(): bool
    {
        return SubscriptionStatus::ACTIVE === $this->status
            && $this->currentPeriodEnd > new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * isPremium = active OR (past_due AND current_period_end > now).
     * Grace period past_due : l'accès Premium est maintenu jusqu'à current_period_end.
     */
    public function isPremium(): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return \in_array($this->status, [SubscriptionStatus::ACTIVE, SubscriptionStatus::PAST_DUE], true)
            && $this->currentPeriodEnd > $now;
    }
}
