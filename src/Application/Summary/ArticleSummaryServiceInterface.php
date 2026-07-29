<?php

declare(strict_types=1);

namespace App\Application\Summary;

use App\Domain\Summary\ArticleSummary;

/**
 * Interface Application — Service de génération de condensés IA par article (US-004).
 *
 * Permet le mock dans les tests unitaires de BriefController.
 * Implémenté par App\Application\Summary\ArticleSummaryService.
 *
 * Couche Application — dépend uniquement de Domain.
 * Deptrac : Application:[Domain].
 */
interface ArticleSummaryServiceInterface
{
    /**
     * Retourne le condensé IA pour un article (cache-aside Redis + fallback).
     *
     * Flux :
     * 1. Cache Redis (clé sha256(articleId)) → retour immédiat si chaud
     * 2. Mistral API (circuit breaker) → cache + persist si disponible
     * 3. OpenAI API (circuit breaker) → cache + persist si Mistral KO
     * 4. Dégradé : extrait RSS brut ≤ 280 chars + badge "RÉSUMÉ AUTOMATIQUE INDISPONIBLE"
     *
     * @param string $articleId UUID de l'article (clé de cache, jamais envoyé au LLM)
     * @param string $articleText Texte de l'article à synthétiser (PII-free — RGPD)
     *
     * @return ArticleSummary Condensé IA (ou dégradé si tous les fournisseurs sont KO)
     */
    public function getSummary(string $articleId, string $articleText): ArticleSummary;
}
