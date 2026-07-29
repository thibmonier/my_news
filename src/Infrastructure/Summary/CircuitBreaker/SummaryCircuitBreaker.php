<?php

declare(strict_types=1);

namespace App\Infrastructure\Summary\CircuitBreaker;

use App\Domain\Summary\SummaryCircuitBreakerInterface;
use Predis\Client;

/**
 * Adapter Infrastructure — Circuit breaker Redis pour les fournisseurs IA (US-004).
 *
 * Implémente SummaryCircuitBreakerInterface (port Domain) via Predis.
 *
 * Comportement (US-004 Conversation §4) :
 * - Clé Redis : `briefly:cb:summary:{provider}` (TTL 60s)
 * - Seuil d'ouverture : 2 échecs successifs
 * - Durée ouverture : 60 secondes (reset automatique par TTL Redis)
 * - Fermeture : succès → suppression de la clé (compteur remis à 0)
 *
 * Providers : 'mistral' | 'openai'
 *
 * Deptrac : Infrastructure → Domain (SummaryCircuitBreakerInterface).
 */
final class SummaryCircuitBreaker implements SummaryCircuitBreakerInterface
{
    private const KEY_PREFIX = 'briefly:cb:summary:';
    private const FAILURE_THRESHOLD = 2;
    private const RESET_TTL = 60; // secondes

    public function __construct(
        private readonly Client $redisClient,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function isOpen(string $provider): bool
    {
        try {
            $count = (int) ($this->redisClient->get($this->buildKey($provider)) ?? 0);

            return $count >= self::FAILURE_THRESHOLD;
        } catch (\Throwable) {
            // Fail-safe : si Redis est KO, le circuit est considéré fermé
            // (on laisse le fournisseur IA tenter sa chance)
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function recordFailure(string $provider): void
    {
        try {
            $key = $this->buildKey($provider);
            $count = $this->redisClient->incr($key);

            // Positionner l'expiry uniquement au 1er incrément
            // (évite de repousser le TTL sur chaque échec)
            if (1 === $count) {
                $this->redisClient->expire($key, self::RESET_TTL);
            }
        } catch (\Throwable) {
            // Fail-safe : si Redis KO, on ignore l'erreur
        }
    }

    /**
     * {@inheritDoc}
     */
    public function recordSuccess(string $provider): void
    {
        try {
            $this->redisClient->del([$this->buildKey($provider)]);
        } catch (\Throwable) {
            // Fail-safe : si Redis KO, on ignore
        }
    }

    /**
     * Construit la clé Redis du circuit breaker.
     *
     * Format : `briefly:cb:summary:{provider}`
     * Aucun identifiant utilisateur dans la clé (RGPD-safe).
     */
    private function buildKey(string $provider): string
    {
        return self::KEY_PREFIX . $provider;
    }
}
