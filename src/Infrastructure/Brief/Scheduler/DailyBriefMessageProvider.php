<?php

declare(strict_types=1);

namespace App\Infrastructure\Brief\Scheduler;

use App\Application\Brief\GenerateDailyBrief\GenerateDailyBriefMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\MessageProviderInterface;

/**
 * Fournisseur de messages dynamique — génère GenerateDailyBriefMessage à la date UTC du déclenchement.
 *
 * Permet au Scheduler de produire un message avec la date du jour réelle au moment de l'exécution,
 * contrairement à un message statique dont la date serait figée à l'enregistrement du schedule.
 *
 * SÉCURITÉ : aucune donnée personnelle dans le message (dateTarget uniquement).
 *
 * Deptrac Infrastructure:[Domain, Application] — dépend de GenerateDailyBriefMessage (Application).
 */
final class DailyBriefMessageProvider implements MessageProviderInterface
{
    private const PROVIDER_ID = 'briefly.daily_brief_generator';

    /**
     * Génère un GenerateDailyBriefMessage avec la date UTC du déclenchement.
     *
     * @param MessageContext $context contexte Symfony Scheduler (contient triggeredAt)
     *
     * @return iterable<GenerateDailyBriefMessage>
     */
    public function getMessages(MessageContext $context): iterable
    {
        // La date cible est la date UTC du moment où le cron se déclenche (05:00:00 UTC).
        $dateUtc = $context->triggeredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');

        yield new GenerateDailyBriefMessage($dateUtc);
    }

    public function getId(): string
    {
        return self::PROVIDER_ID;
    }
}
