<?php

declare(strict_types=1);

namespace App\Application\Subscription;

/**
 * Message Messenger pour les événements Stripe reçus via webhook.
 *
 * eventId est l'id Stripe (ex: evt_1ABCDEF) — sert de clé d'idempotence.
 * payload ne doit JAMAIS apparaître dans les logs (peut contenir des données client).
 */
final readonly class StripeWebhookMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $eventType,
        public readonly string $eventId,
        public readonly array $payload,
    ) {
    }
}
