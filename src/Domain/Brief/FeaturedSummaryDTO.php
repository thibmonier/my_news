<?php

declare(strict_types=1);

namespace App\Domain\Brief;

/**
 * Value Object — Synthèse narrative éditoriale du Daily Brief (US-006).
 *
 * PHP pur — AUCUN import Symfony/Doctrine/ApiPlatform (constitution §4).
 *
 * Représente la synthèse narrative générée par Mistral à partir des 3 histoires
 * sélectionnées du Daily Brief. Affichée en haut du /brief (desktop uniquement).
 *
 * RGPD : aucun identifiant utilisateur — content = texte éditorial PII-free.
 * INV-2 : le badge émeraude #10B981 est affiché UNIQUEMENT si isFallback = false.
 */
final readonly class FeaturedSummaryDTO
{
    /**
     * @param string $briefId UUID du Daily Brief source (FK)
     * @param string $content Texte narratif 80-120 mots (ou texte fallback)
     * @param string $modelVersion Version Mistral ('mistral-small-latest' ou '' si fallback)
     * @param \DateTimeImmutable $generatedAt Horodatage UTC de génération
     * @param bool $isFallback true si Mistral était KO lors de la génération
     */
    public function __construct(
        public readonly string $briefId,
        public readonly string $content,
        public readonly string $modelVersion,
        public readonly \DateTimeImmutable $generatedAt,
        public readonly bool $isFallback = false,
    ) {
    }
}
