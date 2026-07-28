<?php

declare(strict_types=1);

namespace App\Infrastructure\Brief\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Fournisseur de planning — Déclenche la génération du Daily Brief à 05:00 UTC (7j/7).
 *
 * Implémente ScheduleProviderInterface pour le Symfony Scheduler.
 * Enregistré sous le nom de schedule "brief" (@AsSchedule).
 * Worker : `bin/console messenger:consume scheduler_brief`
 *
 * Comportement :
 * - CronExpression "0 5 * * *" → déclenchement à 05:00:00 UTC chaque jour
 * - DailyBriefMessageProvider génère GenerateDailyBriefMessage avec la date UTC réelle
 * - Le message est routé par Messenger vers le transport "async" (config messenger.yaml)
 * - GenerateDailyBriefHandler consomme le message et acquiert le lock Redis (anti-doublon)
 *
 * Protection contre les double-exécutions : le lock Redis est géré dans GenerateDailyBriefHandler,
 * pas dans le scheduler (séparation des responsabilités).
 *
 * ADR-006 : Symfony Scheduler choisi pour éviter la dépendance cron système externe.
 * Déployé dans le même container FrankenPHP (US-003 Conversation §1).
 *
 * Deptrac Infrastructure:[Domain, Application] — dépend de DailyBriefMessageProvider (Infrastructure).
 */
#[AsSchedule('brief')]
final class BriefScheduleProvider implements ScheduleProviderInterface
{
    /**
     * Retourne le planning de génération du Daily Brief.
     *
     * Le CronExpressionTrigger "0 5 * * *" se déclenche à 05:00 UTC tous les jours
     * (l'expression cron est interprétée en UTC par défaut).
     */
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::cron(
                '0 5 * * *',
                new DailyBriefMessageProvider(),
                new \DateTimeZone('UTC'),
            ),
        );
    }
}
