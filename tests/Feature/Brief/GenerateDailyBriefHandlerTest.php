<?php

declare(strict_types=1);

use App\Application\Brief\GenerateDailyBrief\GenerateDailyBriefHandler;
use App\Application\Brief\GenerateDailyBrief\GenerateDailyBriefMessage;
use App\Domain\Brief\BriefGenerationFailedEvent;
use App\Domain\Brief\BriefSelectorService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/*
 * Feature tests — GenerateDailyBriefHandler
 *
 * Teste l'intégration du Handler dans le conteneur Symfony (autowiring, services).
 * Les dépendances sont remplacées par des doubles pour l'isolation.
 *
 * Couvre :
 * - 0 articles disponibles → BriefGenerationFailedEvent dispatché + log ERROR
 * - Timeout DB → exception propagée (retry Messenger)
 * - Nominal → log INFO émis, pas d'événement d'échec
 * - LockFactory injecté → lock créé et géré (US-003)
 */
uses(KernelTestCase::class);

/**
 * Crée un LockFactory stub dont le lock acquiert toujours (mode nominal pour les tests existants).
 */
function featureLockFactoryStub(): LockFactory
{
    $lockMock = new class implements LockInterface {
        public function acquire(bool $blocking = false): bool
        {
            return true;
        }

        public function release(): void
        {
        }

        public function refresh(?float $ttl = null): void
        {
        }

        public function isAcquired(): bool
        {
            return true;
        }

        public function isExpired(): bool
        {
            return false;
        }

        public function getRemainingLifetime(): ?float
        {
            return 600.0;
        }
    };

    return new class($lockMock) extends LockFactory {
        public function __construct(private readonly LockInterface $lock)
        {
            // Bypass du constructeur parent pour les tests Feature
        }

        public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): LockInterface
        {
            return $this->lock;
        }
    };
}

// ── T-002-11 : 0 articles disponibles → BriefGenerationFailedEvent dispatché ─

test('Handler : 0 articles → BriefGenerationFailedEvent dispatché et log ERROR', function (): void {
    self::bootKernel();
    $container = static::getContainer();

    // Double du BriefSelectorService retournant un BriefGenerationFailedEvent
    $failedEvent = new BriefGenerationFailedEvent(
        targetDate: new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC')),
        reason: 'no_articles_available',
    );

    $selectorMock = $this->createMock(BriefSelectorService::class);
    $selectorMock->method('selectTopStories')->willReturn($failedEvent);

    $dispatchedEvents = [];
    $dispatcherMock = $this->createMock(EventDispatcherInterface::class);
    $dispatcherMock->expects($this->once())
        ->method('dispatch')
        ->with($this->isInstanceOf(BriefGenerationFailedEvent::class))
        ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
            $dispatchedEvents[] = $event;

            return $event;
        });

    $loggedErrors = [];
    $loggerMock = $this->createMock(LoggerInterface::class);
    $loggerMock->expects($this->atLeastOnce())
        ->method('error')
        ->willReturnCallback(static function (string $message, array $context = []) use (&$loggedErrors): void {
            $loggedErrors[] = $message;
        });
    $loggerMock->method('info')->willReturn(null);
    $loggerMock->method('warning')->willReturn(null);

    $handler = new GenerateDailyBriefHandler(
        $selectorMock,
        $dispatcherMock,
        $loggerMock,
        featureLockFactoryStub(),
    );

    $msg = new GenerateDailyBriefMessage('2026-07-28');
    $handler($msg);

    // Vérifications
    expect($dispatchedEvents)->toHaveCount(1, 'BriefGenerationFailedEvent dispatché 1 fois');
    expect($dispatchedEvents[0]->reason)->toBe('no_articles_available');
    expect($loggedErrors)->not->toBeEmpty('log ERROR enregistré');
})->group('handler');

// ── T-002-11 : Timeout DB → exception propagée pour retry Messenger ──────────

test('Handler : timeout DB → exception propagée pour retry Messenger', function (): void {
    self::bootKernel();

    $timeoutException = new RuntimeException('Query timeout exceeded');

    $selectorMock = $this->createMock(BriefSelectorService::class);
    $selectorMock->method('selectTopStories')->willThrowException($timeoutException);

    $dispatcherMock = $this->createMock(EventDispatcherInterface::class);
    $dispatcherMock->expects($this->never())->method('dispatch');

    $loggerMock = $this->createMock(LoggerInterface::class);
    $loggerMock->expects($this->atLeastOnce())->method('error');
    $loggerMock->method('info')->willReturn(null);
    $loggerMock->method('warning')->willReturn(null);

    $handler = new GenerateDailyBriefHandler(
        $selectorMock,
        $dispatcherMock,
        $loggerMock,
        featureLockFactoryStub(),
    );

    $msg = new GenerateDailyBriefMessage('2026-07-28');

    // L'exception doit être propagée pour que Messenger marque le message "failed"
    expect(static fn () => $handler($msg))->toThrow(RuntimeException::class);
})->group('handler');

// ── Nominal : sélection réussie → log INFO, pas d'événement d'échec ──────────

test('Handler : sélection réussie → log INFO brief.batch_success émis, pas d\'événement dispatché', function (): void {
    self::bootKernel();

    $selectorMock = $this->createMock(BriefSelectorService::class);
    $selectorMock->method('selectTopStories')->willReturn(null); // Succès

    $dispatcherMock = $this->createMock(EventDispatcherInterface::class);
    $dispatcherMock->expects($this->never())->method('dispatch');

    $loggedInfos = [];
    $loggerMock = $this->createMock(LoggerInterface::class);
    $loggerMock->method('error')->willReturn(null);
    $loggerMock->method('warning')->willReturn(null);
    $loggerMock->expects($this->atLeastOnce())
        ->method('info')
        ->willReturnCallback(static function (string $message, array $context = []) use (&$loggedInfos): void {
            $loggedInfos[] = $context['event'] ?? $message;
        });

    $handler = new GenerateDailyBriefHandler(
        $selectorMock,
        $dispatcherMock,
        $loggerMock,
        featureLockFactoryStub(),
    );

    $msg = new GenerateDailyBriefMessage('2026-07-28');
    $handler($msg);

    expect($loggedInfos)->not->toBeEmpty('log INFO émis');
    expect($loggedInfos)->toContain('brief.batch_success', 'log brief.batch_success attendu');
})->group('handler');

// ── Test : service autowired dans le conteneur ────────────────────────────────

test('BriefSelectorService est correctement auto-câblé dans le conteneur', static function (): void {
    self::bootKernel();
    $container = static::getContainer();

    $service = $container->get(BriefSelectorService::class);
    expect($service)->toBeInstanceOf(BriefSelectorService::class);
})->group('container');

test('GenerateDailyBriefHandler est correctement auto-câblé dans le conteneur', static function (): void {
    self::bootKernel();
    $container = static::getContainer();

    $handler = $container->get(GenerateDailyBriefHandler::class);
    expect($handler)->toBeInstanceOf(GenerateDailyBriefHandler::class);
})->group('container');
