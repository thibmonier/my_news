<?php

declare(strict_types=1);

namespace App\Domain\Brief;

/**
 * Port Domaine — Persistance des synthèses narratives du Daily Brief (US-006).
 *
 * Port secondaire (driven) pour la persistance en PostgreSQL.
 * Implémenté par App\Infrastructure\Brief\Persistence\DoctrineDailyBriefSummaryRepository.
 *
 * Table `daily_brief_summaries` :
 * - id            : UUID v4, PK
 * - brief_id      : UUID FK UNIQUE → daily_briefs.id
 * - content       : TEXT  — texte narratif 80-120 mots (ou fallback)
 * - model_version : VARCHAR(64) — version Mistral ou '' si fallback
 * - generated_at  : TIMESTAMPTZ UTC de génération
 * - is_fallback   : BOOLEAN DEFAULT FALSE — true si Mistral KO
 *
 * RGPD : aucune donnée personnelle — uniquement contenu éditorial + UUID brief.
 * INV-2 : le badge IA est réservé aux entrées is_fallback = false.
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
interface DailyBriefSummaryRepositoryInterface
{
    /**
     * Récupère la synthèse narrative pour un brief donné.
     *
     * @param string $briefId UUID du DailyBrief
     *
     * @return FeaturedSummaryDTO|null null si aucune synthèse générée pour ce brief
     */
    public function findByBriefId(string $briefId): ?FeaturedSummaryDTO;

    /**
     * Récupère la synthèse narrative la plus récente (tous briefs confondus).
     *
     * Utilisé par le contrôleur pour afficher la synthèse du brief courant
     * sans avoir besoin du briefId (découplage de la vue publique).
     *
     * @return FeaturedSummaryDTO|null null si aucune synthèse en base
     */
    public function findLatest(): ?FeaturedSummaryDTO;

    /**
     * Persiste ou remplace la synthèse narrative d'un brief.
     *
     * UPSERT : si une entrée existe déjà pour ce brief_id, elle est remplacée.
     *
     * @param FeaturedSummaryDTO $summary Synthèse narrative à persister
     */
    public function save(FeaturedSummaryDTO $summary): void;
}
