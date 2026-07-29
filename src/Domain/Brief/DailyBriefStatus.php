<?php

declare(strict_types=1);

namespace App\Domain\Brief;

/**
 * Statut d'un DailyBrief.
 *
 * PHP pur — AUCUN import Symfony/Doctrine.
 * Constitution §4 : entités Domain = classes PHP pures.
 *
 * - pending : sélection en cours
 * - ready   : sélection terminée, brief consultable
 * - error   : sélection échouée (0 articles disponibles)
 */
enum DailyBriefStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Error = 'error';
}
