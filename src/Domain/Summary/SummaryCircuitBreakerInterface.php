<?php

declare(strict_types=1);

namespace App\Domain\Summary;

/**
 * Port Domaine — Circuit breaker pour les fournisseurs IA de condensés (US-004).
 *
 * Implémenté par App\Infrastructure\Summary\CircuitBreaker\SummaryCircuitBreaker (Redis).
 *
 * Comportement (US-004 Conversation §4) :
 * - Ouverture : 2 timeouts successifs → circuit ouvert 60 secondes
 * - Fermeture : appel réussi → remise à zéro du compteur d'échecs
 *
 * Providers supportés : 'mistral', 'openai'
 * Clé Redis : `briefly:cb:summary:{provider}` (TTL 60s)
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
interface SummaryCircuitBreakerInterface
{
    /**
     * Vérifie si le circuit est ouvert (fournisseur considéré indisponible).
     *
     * @param string $provider Identifiant du fournisseur ('mistral' | 'openai')
     *
     * @return bool true si le circuit est ouvert (≥ 2 échecs récents)
     */
    public function isOpen(string $provider): bool;

    /**
     * Enregistre un échec et potentiellement ouvre le circuit.
     *
     * @param string $provider Identifiant du fournisseur
     */
    public function recordFailure(string $provider): void;

    /**
     * Réinitialise le compteur d'échecs après un succès.
     *
     * @param string $provider Identifiant du fournisseur
     */
    public function recordSuccess(string $provider): void;
}
