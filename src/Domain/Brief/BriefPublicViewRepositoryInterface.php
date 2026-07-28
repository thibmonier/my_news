<?php

declare(strict_types=1);

namespace App\Domain\Brief;

/**
 * Port secondaire — Vue publique du Daily Brief pour l'affichage web (US-001).
 *
 * Ségrégation ISP : interface spécialisée pour la lecture en couche Presentation,
 * distincte de DailyBriefRepositoryInterface (qui gère la génération + persistance).
 *
 * Retourne un read model enrichi (brief + articles + sources) en une seule requête
 * optimisée, sans N+1 queries.
 *
 * Constitution §4 : interfaces dans le Domain, implémentations dans Infrastructure (DIP).
 * Deptrac Domain:[] — aucune dépendance framework dans ce fichier.
 */
interface BriefPublicViewRepositoryInterface
{
    /**
     * Retourne le Daily Brief public le plus récent (status = ready).
     *
     * Algorithme de priorité :
     * 1. Brief du jour (date = aujourd'hui UTC) avec status = 'ready'
     * 2. Sinon : dernier brief avec status = 'ready' (brief de J-1 ou antérieur)
     * 3. Sinon : null (table vide ou aucun brief en état 'ready')
     *
     * Garanties :
     * - Jointure avec articles + sources en une requête (pas de N+1)
     * - excerpt tronqué à 280 caractères dans la requête (logique dans repository)
     * - Stories triées par position ASC (01, 02, 03)
     *
     * @return BriefPublicView|null null = aucun brief disponible (empty state US-001)
     */
    public function findLatestPublicView(): ?BriefPublicView;
}
