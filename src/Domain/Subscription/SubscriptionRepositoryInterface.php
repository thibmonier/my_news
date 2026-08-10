<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Port secondaire — persistance des abonnements Premium.
 *
 * save() utilise INSERT ... ON CONFLICT (stripe_event_id) DO NOTHING
 * pour garantir l'idempotence des webhooks Stripe.
 */
interface SubscriptionRepositoryInterface
{
    /**
     * Persiste un abonnement.
     * Idempotent : si stripe_event_id existe déjà, l'opération est ignorée silencieusement.
     */
    public function save(Subscription $subscription): void;

    /**
     * Vérifie si l'utilisateur a un abonnement actif.
     * status = 'active' AND current_period_end > NOW().
     */
    public function isPremium(string $userUuid): bool;

    public function findByStripeEventId(string $eventId): ?Subscription;

    public function findByStripeSubscriptionId(string $subscriptionId): ?Subscription;

    public function findByStripeCustomerId(string $customerId): ?Subscription;
}
