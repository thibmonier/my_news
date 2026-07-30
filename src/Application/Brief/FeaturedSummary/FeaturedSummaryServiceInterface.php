<?php

declare(strict_types=1);

namespace App\Application\Brief\FeaturedSummary;

use App\Domain\Brief\BriefStoryPublicView;
use App\Domain\Brief\FeaturedSummaryDTO;

/**
 * Interface Application — Service Featured Summary (US-006).
 *
 * Deux points d'entrée :
 * - generateForBrief : appelé par GenerateDailyBriefHandler après la sélection (batch 5h UTC)
 * - getForToday      : appelé par BriefController pour l'affichage en temps réel
 *
 * Couche Application — PHP pur, dépend uniquement du Domain.
 */
interface FeaturedSummaryServiceInterface
{
    /**
     * Génère et persiste la synthèse narrative pour un brief donné.
     *
     * Flux : cache-aside → Mistral → fallback si Mistral KO → persistance DB + Redis.
     *
     * RGPD : le prompt envoyé à Mistral ne contient JAMAIS d'identifiant utilisateur
     * (test bloquant T-006-09 — assert `assertNotContains` user_id/email/session_id).
     *
     * @param string $briefId UUID du DailyBrief
     * @param \DateTimeImmutable $date Date du brief (pour la clé cache et le texte fallback)
     * @param list<BriefStoryPublicView> $stories Les 3 histoires sélectionnées (titre + extrait)
     *
     * @return FeaturedSummaryDTO Synthèse générée (nominale ou fallback)
     */
    public function generateForBrief(
        string $briefId,
        \DateTimeImmutable $date,
        array $stories,
    ): FeaturedSummaryDTO;

    /**
     * Récupère la synthèse narrative du jour pour affichage dans /brief.
     *
     * Flux : cache Redis → DB (latest) → null si aucune synthèse disponible.
     * La non-disponibilité est silencieuse (page affichée sans le Featured Summary).
     *
     * @param \DateTimeImmutable $now Date/heure courante (injectée pour la testabilité)
     *
     * @return FeaturedSummaryDTO|null null si aucune synthèse disponible (silencieux)
     */
    public function getForToday(\DateTimeImmutable $now): ?FeaturedSummaryDTO;
}
