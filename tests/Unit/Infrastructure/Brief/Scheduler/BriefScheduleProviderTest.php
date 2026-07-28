<?php

declare(strict_types=1);

use App\Application\Brief\GenerateDailyBrief\GenerateDailyBriefMessage;
use App\Infrastructure\Brief\Scheduler\BriefScheduleProvider;
use App\Infrastructure\Brief\Scheduler\DailyBriefMessageProvider;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

/*
 * Unit tests — BriefScheduleProvider + DailyBriefMessageProvider (T-003-06)
 *
 * Couvre les scénarios Gherkin US-003 :
 * - Le schedule retourne bien un RecurringMessage
 * - Le trigger est un CronExpressionTrigger avec l'expression "0 5 * * *"
 * - Le message provider est bien un DailyBriefMessageProvider
 * - DailyBriefMessageProvider génère GenerateDailyBriefMessage avec la date UTC
 * - Déclenchement 7j/7 (pas de restriction jour de semaine)
 *
 * Tests PHP purs (pas de Symfony Kernel) — BriefScheduleProvider n'a pas de dépendances.
 */

// ── T-003-06 : Le schedule retourne au moins un RecurringMessage ─────────────

test('BriefScheduleProvider retourne un Schedule avec au moins un RecurringMessage', function (): void {
    $provider = new BriefScheduleProvider();
    $schedule = $provider->getSchedule();

    $messages = $schedule->getRecurringMessages();
    expect($messages)->not->toBeEmpty('Le schedule doit contenir au moins un RecurringMessage');
    expect($messages[0])->toBeInstanceOf(RecurringMessage::class);
})->group('scheduler');

// ── T-003-06 : Le trigger est un CronExpressionTrigger ───────────────────────

test('BriefScheduleProvider utilise un CronExpressionTrigger', function (): void {
    $provider = new BriefScheduleProvider();
    $messages = $provider->getSchedule()->getRecurringMessages();

    expect($messages[0]->getTrigger())->toBeInstanceOf(CronExpressionTrigger::class);
})->group('scheduler');

// ── T-003-06 : L'expression cron est exactement "0 5 * * *" ──────────────────

test('BriefScheduleProvider utilise l\'expression cron "0 5 * * *" (05:00 UTC quotidien)', function (): void {
    $provider = new BriefScheduleProvider();
    $trigger = $provider->getSchedule()->getRecurringMessages()[0]->getTrigger();

    // CronExpressionTrigger::__toString() retourne l'expression cron brute
    expect((string) $trigger)->toBe('0 5 * * *');
})->group('scheduler');

// ── T-003-06 : Déclenchement 7j/7 ────────────────────────────────────────────

test('BriefScheduleProvider se déclenche 7j/7 (lundi au dimanche)', function (): void {
    $provider = new BriefScheduleProvider();
    $trigger = $provider->getSchedule()->getRecurringMessages()[0]->getTrigger();

    // Vérification que le trigger se déclenche chaque jour de la semaine (Lundi-Dimanche)
    // Semaine du 5 au 11 janvier 2026 (Lun-Dim)
    for ($day = 0; $day < 7; ++$day) {
        $checkFrom = new DateTimeImmutable(
            sprintf('2026-01-%02d 04:59:59', $day + 5),
            new DateTimeZone('UTC'),
        );
        $nextRun = $trigger->getNextRunDate($checkFrom);
        expect($nextRun)->not->toBeNull(
            sprintf('Trigger doit se déclencher le jour %d de la semaine', $day + 1),
        );
        // La prochaine exécution doit être à 05:00:00 le même jour
        expect($nextRun->format('H:i:s'))->toBe('05:00:00', 'Heure de déclenchement incorrecte');
    }
})->group('scheduler');

// ── T-003-06 : DailyBriefMessageProvider génère GenerateDailyBriefMessage ─────

test('DailyBriefMessageProvider génère GenerateDailyBriefMessage avec la date UTC du déclenchement', function (): void {
    $messageProvider = new DailyBriefMessageProvider();

    // Stub minimal de TriggerInterface pour le MessageContext
    $triggerStub = new class implements TriggerInterface {
        public function __toString(): string
        {
            return 'stub-0 5 * * *';
        }

        public function getNextRunDate(DateTimeImmutable $run): ?DateTimeImmutable
        {
            return $run;
        }
    };

    // Simulation d'un MessageContext à 05:00:00 UTC le 28 juillet 2026
    $triggeredAt = new DateTimeImmutable('2026-07-28 05:00:00', new DateTimeZone('UTC'));
    $context = new MessageContext(
        name: 'brief',
        id: 'test-daily-brief-id',
        trigger: $triggerStub,
        triggeredAt: $triggeredAt,
    );

    $generatedMessages = iterator_to_array($messageProvider->getMessages($context));

    expect($generatedMessages)->toHaveCount(1, 'Un seul message généré par déclenchement');
    expect($generatedMessages[0])->toBeInstanceOf(GenerateDailyBriefMessage::class);
    expect($generatedMessages[0]->dateTarget)->toBe('2026-07-28');
    expect($generatedMessages[0]->getDate()->getTimezone()->getName())->toBe('UTC');
})->group('scheduler');

// ── T-003-06 : DailyBriefMessageProvider a un ID stable ──────────────────────

test('DailyBriefMessageProvider::getId() retourne un identifiant non vide et stable', function (): void {
    $provider = new DailyBriefMessageProvider();

    expect($provider->getId())->not->toBeEmpty();
    // Stable entre deux appels
    expect($provider->getId())->toBe($provider->getId());
})->group('scheduler');

// ── T-003-06 : BriefScheduleProvider::getSchedule() est idempotent ───────────

test('BriefScheduleProvider::getSchedule() retourne des RecurringMessages avec IDs stables', function (): void {
    $provider = new BriefScheduleProvider();

    $ids1 = array_map(
        static fn (RecurringMessage $m): string => $m->getId(),
        $provider->getSchedule()->getRecurringMessages(),
    );
    $ids2 = array_map(
        static fn (RecurringMessage $m): string => $m->getId(),
        $provider->getSchedule()->getRecurringMessages(),
    );

    expect($ids1)->toBe($ids2, 'Les IDs doivent être stables pour le checkpoint Scheduler');
})->group('scheduler');
