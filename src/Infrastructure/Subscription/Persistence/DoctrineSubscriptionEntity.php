<?php

declare(strict_types=1);

namespace App\Infrastructure\Subscription\Persistence;

use App\Domain\Subscription\Subscription;
use App\Domain\Subscription\SubscriptionPlan;
use App\Domain\Subscription\SubscriptionStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'subscriptions')]
#[ORM\UniqueConstraint(name: 'subscriptions_stripe_customer_id_unique', columns: ['stripe_customer_id'])]
#[ORM\UniqueConstraint(name: 'subscriptions_stripe_subscription_id_unique', columns: ['stripe_subscription_id'])]
#[ORM\UniqueConstraint(name: 'subscriptions_stripe_event_id_unique', columns: ['stripe_event_id'])]
#[ORM\Index(name: 'idx_subscriptions_status_period_end', columns: ['status', 'current_period_end'])]
class DoctrineSubscriptionEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'user_id', type: 'guid')]
    private string $userId;

    #[ORM\Column(name: 'stripe_customer_id', length: 255, unique: true)]
    private string $stripeCustomerId;

    #[ORM\Column(name: 'stripe_subscription_id', length: 255, unique: true)]
    private string $stripeSubscriptionId;

    #[ORM\Column(type: 'string', length: 10, enumType: SubscriptionPlan::class)]
    private SubscriptionPlan $plan;

    #[ORM\Column(type: 'string', length: 20, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status;

    #[ORM\Column(name: 'current_period_end', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $currentPeriodEnd;

    #[ORM\Column(name: 'stripe_event_id', length: 255, unique: true)]
    private string $stripeEventId;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __construct(
        string $id,
        string $userId,
        string $stripeCustomerId,
        string $stripeSubscriptionId,
        SubscriptionPlan $plan,
        SubscriptionStatus $status,
        \DateTimeImmutable $currentPeriodEnd,
        string $stripeEventId,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->stripeCustomerId = $stripeCustomerId;
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        $this->plan = $plan;
        $this->status = $status;
        $this->currentPeriodEnd = $currentPeriodEnd;
        $this->stripeEventId = $stripeEventId;
        $this->createdAt = $createdAt;
    }

    public static function fromDomain(Subscription $subscription): self
    {
        return new self(
            id: $subscription->getId(),
            userId: $subscription->getUserId(),
            stripeCustomerId: $subscription->getStripeCustomerId(),
            stripeSubscriptionId: $subscription->getStripeSubscriptionId(),
            plan: $subscription->getPlan(),
            status: $subscription->getStatus(),
            currentPeriodEnd: $subscription->getCurrentPeriodEnd(),
            stripeEventId: $subscription->getStripeEventId(),
            createdAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function toDomain(): Subscription
    {
        return new Subscription(
            id: $this->id,
            userId: $this->userId,
            stripeCustomerId: $this->stripeCustomerId,
            stripeSubscriptionId: $this->stripeSubscriptionId,
            plan: $this->plan,
            status: $this->status,
            currentPeriodEnd: $this->currentPeriodEnd,
            stripeEventId: $this->stripeEventId,
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStripeEventId(): string
    {
        return $this->stripeEventId;
    }
}
