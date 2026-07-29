<?php

declare(strict_types=1);

namespace App\Domain\Summary;

/**
 * Port Domaine — Client IA pour la génération de condensés (US-004).
 *
 * Abstraction du fournisseur IA pour permettre :
 * - Le test unitaire de ArticleSummaryService sans appel réseau réel
 * - Le fallback Mistral → OpenAI (DIP SOLID — US-004 Conversation §4)
 *
 * Implémentations :
 * - App\Infrastructure\Summary\Ai\MistralSummaryClient (primaire)
 * - App\Infrastructure\Summary\Ai\OpenAiSummaryClient (fallback)
 *
 * Sécurité RGPD :
 * - Le paramètre $articleText ne doit JAMAIS contenir de PII (email, UUID utilisateur, IP)
 * - Le paramètre $articleId est l'UUID de l'article (non réversible vers données personnelles)
 * - Les implémentations logguent uniquement article_id et model_version (jamais le prompt complet)
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
interface SummaryClientInterface
{
    /**
     * Génère un condensé IA de 3 à 4 puces pour le texte de l'article.
     *
     * @param string $articleText Texte de l'article à synthétiser (PII-free — RGPD)
     * @param string $articleId UUID de l'article source (pour logging uniquement, jamais dans le prompt)
     *
     * @throws SummaryUnavailableException si le fournisseur IA est temporairement indisponible
     *
     * @return ArticleSummary Condensé structuré (3-4 puces ≤ 120 chars chacune)
     */
    public function summarize(string $articleText, string $articleId): ArticleSummary;
}
