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
     * 1. Validation SSRF de l'URL (rejet IP RFC1918 + schéma http/https)
     * 2. Fetch du contenu de l'article
     * 3. Appel MistralClientInterface (prompt contrôlé ~200 mots, 3 points clés, sources)
     * 4. Parse de la réponse Mistral
     * 5. Persistence SynthesisResult
     *
     * @param SynthesisRequest $request URL source de l'article à synthétiser
     *
     * @throws InvalidSynthesisUrlException si l'URL est malformée ou cible une IP privée (SSRF)
     * @throws SynthesisUnavailableException si Mistral est inaccessible (timeout, erreur réseau)
     *
     * @return SynthesisResponse Synthèse IA avec content, keyPoints, sources, originalUrl, isPartial
     */
    public function synthesize(SynthesisRequest $request): SynthesisResponse;
}
