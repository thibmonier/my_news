<?php

declare(strict_types=1);

use App\Application\Brief\GenerateDailyBrief\GenerateDailyBriefMessage;
use App\Presentation\Command\GenerateDailyBriefCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/*
 * Unit tests — GenerateDailyBriefCommand (T-003-08)
 *
 * Couvre les scénarios Gherkin US-003 (alternatif 1) :
 * - Nominal : commande dispatche GenerateDailyBriefMessage → exit code 0
 * - Option --date=YYYY-MM-DD : message créé avec la date spécifiée
 * - Sans --date : message créé avec la date du jour UTC
 * - Log brief.manual_trigger avec operator=console et sans identifiant utilisateur
 *
 * Utilise PHPUnit TestCase + CommandTester (pas de boot Kernel).
 */
uses(TestCase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Crée un MessageBusInterface stub qui capture les messages dispatchés.
 *
 * @param list<object> &$dispatched Tableau capturant les messages
 */
function messageBusStub(array &$dispatched): MessageBusInterface
{
    return new class($dispatched) implements MessageBusInterface {
        public function __construct(private array &$messages)
        {
        }

        public function dispatch(object $message, array $stamps = []): Envelope
        {
            $this->messages[] = $message;

            return new Envelope($message, $stamps);
        }
    };
}

// ── T-003-08 : Nominal — exit code 0 + message dispatché ─────────────────────

test('GenerateDailyBriefCommand retourne exit code 0 et dispatche GenerateDailyBriefMessage', function (): void {
    $dispatched = [];
    $bus = messageBusStub($dispatched);
    $logger = $this->createMock(LoggerInterface::class);

    $command = new GenerateDailyBriefCommand($bus, $logger);
    $tester = new CommandTester($command);

    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(Command::SUCCESS, 'Exit code 0 attendu');
    expect($dispatched)->toHaveCount(1, 'Un message doit être dispatché');
    expect($dispatched[0])->toBeInstanceOf(GenerateDailyBriefMessage::class);
})->group('command');

// ── T-003-08 : Option --date=YYYY-MM-DD acceptée ─────────────────────────────

test('Option --date=YYYY-MM-DD : le message utilise la date fournie', function (): void {
    $dispatched = [];
    $bus = messageBusStub($dispatched);
    $logger = $this->createMock(LoggerInterface::class);

    $command = new GenerateDailyBriefCommand($bus, $logger);
    $tester = new CommandTester($command);

    $exitCode = $tester->execute(['--date' => '2026-01-15']);

    expect($exitCode)->toBe(Command::SUCCESS);
    expect($dispatched)->toHaveCount(1);
    expect($dispatched[0])->toBeInstanceOf(GenerateDailyBriefMessage::class);
    expect($dispatched[0]->dateTarget)->toBe('2026-01-15');
})->group('command');

// ── T-003-08 : Sans --date → date du jour UTC ─────────────────────────────────

test('Sans --date : le message utilise la date du jour UTC', function (): void {
    $dispatched = [];
    $bus = messageBusStub($dispatched);
    $logger = $this->createMock(LoggerInterface::class);

    $command = new GenerateDailyBriefCommand($bus, $logger);
    $tester = new CommandTester($command);

    $expectedDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

    $tester->execute([]);

    expect($dispatched[0]->dateTarget)->toBe($expectedDate);
})->group('command');

// ── T-003-08 : Log brief.manual_trigger sans identifiant utilisateur ──────────

test('Log brief.manual_trigger avec operator=console et sans identifiant utilisateur', function (): void {
    $dispatched = [];
    $bus = messageBusStub($dispatched);

    $loggedContexts = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('info')->willReturnCallback(
        static function (string $msg, array $ctx = []) use (&$loggedContexts): void {
            $loggedContexts[] = $ctx;
        },
    );

    $command = new GenerateDailyBriefCommand($bus, $logger);
    $tester = new CommandTester($command);
    $tester->execute(['--date' => '2026-07-28']);

    // Chercher le log brief.manual_trigger
    $triggerLogs = array_filter(
        $loggedContexts,
        static fn (array $ctx): bool => ($ctx['event'] ?? '') === 'brief.manual_trigger',
    );

    expect($triggerLogs)->not->toBeEmpty('log brief.manual_trigger requis');
    $logCtx = array_values($triggerLogs)[0];

    expect($logCtx['operator'])->toBe('console', 'operator doit être "console"');
    expect($logCtx['date'])->toBe('2026-07-28', 'La date doit être dans le contexte de log');
    // RGPD : pas d'UUID utilisateur dans le log
    expect($logCtx)->not->toHaveKey('user_id', 'Pas d\'identifiant utilisateur dans le log (RGPD)');
    expect($logCtx)->not->toHaveKey('email', 'Pas d\'email dans le log (RGPD)');
})->group('command');

// ── T-003-08 : Date invalide → exception dans le constructeur du message ──────

test('Date invalide : GenerateDailyBriefMessage lève InvalidArgumentException', function (): void {
    $dispatched = [];
    $bus = messageBusStub($dispatched);
    $logger = $this->createMock(LoggerInterface::class);

    $command = new GenerateDailyBriefCommand($bus, $logger);
    $tester = new CommandTester($command);

    expect(static fn () => $tester->execute(['--date' => 'not-a-date']))
        ->toThrow(InvalidArgumentException::class);
})->group('command');

// ── T-003-08 : Option --date peut être omise (facultative) ────────────────────

test('L\'option --date est facultative', function (): void {
    $dispatched = [];
    $bus = messageBusStub($dispatched);
    $logger = $this->createMock(LoggerInterface::class);

    $command = new GenerateDailyBriefCommand($bus, $logger);

    // Vérifie la définition de la commande
    $definition = $command->getDefinition();
    expect($definition->hasOption('date'))->toBeTrue('Option --date doit exister');
    expect($definition->getOption('date')->isValueRequired())->toBeFalse('--date doit être optionnelle');
})->group('command');
