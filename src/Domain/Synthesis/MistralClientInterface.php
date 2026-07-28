<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Port Domaine — Client IA (Mistral API).
 *
 * Abstraction du client HTTP Mistral pour permettre le test unitaire
 * de SynthesisService sans appel réseau réel.
 *
 * Implémenté par App\Infrastructure\Synthesis\Ai\MistralApiClient.
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 *
 * Sécurité :
 * - Jamais de PII (email, UUID utilisateur, IP) dans systemPrompt ou userContent
 * - L'appelant (SynthesisService) garantit cette propriété (assertion CI T-010-11)
 * - Timeout 15s imposé par l'implémentation Infrastructure (T-010-04)
 */
interface MistralClientInterface
{
    /**
     * Soumet un prompt au modèle Mistral et retourne la réponse textuelle.
     *
     * @param string $systemPrompt Instructions système contrôlant le format de sortie
     * @param string $userContent Contenu de l'article à synthétiser (jamais de PII)
     *
     * @throws SynthesisUnavailableException si Mistral timeout, erreur réseau ou HTTP 5xx
     *
     * @return string Réponse textuelle de Mistral (non structurée — parsing côté appelant)
     */
    public function complete(string $systemPrompt, string $userContent): string;
}
