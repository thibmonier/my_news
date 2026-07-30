<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Port Domaine — Service d'orchestration de la synthèse IA.
 *
 * Définit le contrat entre la couche Application et le service de synthèse.
 * Implémenté par App\Application\Synthesis\SynthesisService.
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 *
 * @see \App\Application\Synthesis\SynthesisService
 */
interface SynthesisServiceInterface
{
    /**
     * Génère une synthèse IA pour l'URL fournie.
     *
     * Flux :
     * 1. Normalisation de l'URL (lowercase, tri query params, suppression fragment) — US-012
     * 2. Validation SSRF de l'URL normalisée (rejet IP RFC1918 + schéma http/https)
     * 3. Vérification cache Redis (clé sha256(normalizedUrl . '_' . level))
     * 4. Fetch du contenu de l'article
     * 5. Appel MistralClientInterface (prompt contrôlé ~200 mots, 3 points clés, sources)
     * 6. Parse de la réponse Mistral
     * 7. Persistence SynthesisResult
     * 8. Mise en cache Redis (sauf si Redis indisponible — BYPASS)
     *
     * @param SynthesisRequest $request URL source de l'article à synthétiser
     *
     * @throws InvalidSynthesisUrlException si l'URL est malformée, contient des caractères
     *                                      de contrôle, ou cible une IP privée (SSRF)
     * @throws SynthesisUnavailableException si Mistral est inaccessible (timeout, erreur réseau)
     *
     * @return SynthesisResponseWithCacheStatus Synthèse avec statut cache HIT|MISS|BYPASS (US-012)
     */
    public function synthesize(SynthesisRequest $request): SynthesisResponseWithCacheStatus;
}
