<?php

declare(strict_types=1);

use App\Application\Brief\FeaturedSummary\FeaturedSummaryServiceInterface;
use App\Application\Brief\GenerateDailyBrief\GenerateDailyBriefHandler;
use App\Application\Brief\GenerateDailyBrief\GenerateDailyBriefMessage;
use App\Domain\Brief\BriefPublicViewRepositoryInterface;
use App\Domain\Brief\BriefSelectorServiceInterface;
use App\Domain\Brief\DailyBriefRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/*
 * Unit tests — GenerateDailyBriefHandler avec Lock Redis (T-003-07)
 *
 * Couvre les scénarios Gherkin US-003 :
 * - Nominal : lock acquis → BriefSelectorService exécuté → lock libéré → log brief.batch_success
 * - Lock déjà acquis : TryLock false → log brief.lock_already_acquired → service NON appelé
 * - Redis KO : LockStorageException → log brief.lock_unavailable → mode dégradé → service appelé
 * - Erreur BriefSelectorService : exception propagée pour retry Messenger + lock libéré (finally)
 *
 * Utilise PHPUnit TestCase (createMock) pour contourner la contrainte `final` de BriefSelectorService.
 */
uses(TestCase::class);

// ── T-003-07 : Nominal — lock acquis → service exécuté → lock libéré ────────

test('Nominal : lock acquis → selectTopStories() exécuté → lock libéré → log brief.batch_success', function (): void {
    $date = new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC'));

    // Mock du lock — acquire() retourne true
    $lockMock = $this->createMock(SharedLockInterface::class);
    $lockMock->expects($this->once())->method('acquire')->with(false)->willReturn(true);
    $lockMock->expects($this->once())->method('release');

    $lockFactory = $this->createMock(LockFactory::class);
    $lockFactory->method('createLock')->willReturn($lockMock);

    // Mock du sélecteur — succès (null = pas d'événement d'échec)
    $selectorMock = $this->createMock(BriefSelectorServiceInterface::class);
    $selectorMock->expects($this->once())
        ->method('selectTopStories')
        ->with($this->callback(
            static fn (DateTimeImmutable $d): bool => '2026-07-28' === $d->format('Y-m-d'),
        ))
        ->willReturn(null);

    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->expects($this->never())->method('dispatch');

    $loggedInfos = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('info')->willReturnCallback(
        static function (string $msg, array $ctx = []) use (&$loggedInfos): void {
            $loggedInfos[] = $ctx['event'] ?? $msg;
        },
    );

    $featuredSummaryService = $this->createMock(FeaturedSummaryServiceInterface::class);
    $dailyBriefRepository = $this->createMock(DailyBriefRepositoryInterface::class);
    $dailyBriefRepository->method('findForDate')->willReturn(null); // skip featured summary
    $briefPublicViewRepository = $this->createMock(BriefPublicViewRepositoryInterface::class);

    $handler = new GenerateDailyBriefHandler($selectorMock, $dispatcher, $logger, $lockFactory, $featuredSummaryService, $dailyBriefRepository, $briefPublicViewRepository);
    $handler(new GenerateDailyBriefMessage('2026-07-28'));

    expect($loggedInfos)->toContain('brief.batch_start');
    expect($loggedInfos)->toContain('brief.batch_success');
})->group('handler', 'lock');

// ── T-003-07 : Lock déjà acquis → skip silencieux ────────────────────────────

test('Lock déjà acquis : TryLock=false → log brief.lock_already_acquired → service NON appelé', function (): void {
    // Mock du lock — acquire() retourne false (lock déjà détenu par une autre instance)
    $lockMock = $this->createMock(SharedLockInterface::class);
    $lockMock->expects($this->once())->method('acquire')->with(false)->willReturn(false);
    $lockMock->expects($this->never())->method('release'); // Lock non acquis → pas de release

    $lockFactory = $this->createMock(LockFactory::class);
    $lockFactory->method('createLock')->willReturn($lockMock);

    $selectorMock = $this->createMock(BriefSelectorServiceInterface::class);
    $selectorMock->expects($this->never())->method('selectTopStories'); // NE doit PAS être appelé

    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->expects($this->never())->method('dispatch');

    $loggedInfos = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('info')->willReturnCallback(
        static function (string $msg, array $ctx = []) use (&$loggedInfos): void {
            $loggedInfos[] = $ctx['event'] ?? $msg;
        },
    );

    $featuredSummaryService = $this->createMock(FeaturedSummaryServiceInterface::class);
    $dailyBriefRepository = $this->createMock(DailyBriefRepositoryInterface::class);
    $dailyBriefRepository->method('findForDate')->willReturn(null); // skip featured summary
    $briefPublicViewRepository = $this->createMock(BriefPublicViewRepositoryInterface::class);

    $handler = new GenerateDailyBriefHandler($selectorMock, $dispatcher, $logger, $lockFactory, $featuredSummaryService, $dailyBriefRepository, $briefPublicViewRepository);
    $handler(new GenerateDailyBriefMessage('2026-07-28'));

    expect($loggedInfos)->toContain('brief.lock_already_acquired');
    // batch_success NE doit PAS être logué (exécution ignorée)
    expect($loggedInfos)->not->toContain('brief.batch_success');
})->group('handler', 'lock');

// ── T-003-07 : Redis KO → mode dégradé → service exécuté quand même ──────────

test('Redis KO : LockStorageException → log brief.lock_unavailable → service exécuté (résilience)', function (): void {
    // Mock du lock — acquire() lève LockStorageException (Redis indisponible)
    $lockMock = $this->createMock(SharedLockInterface::class);
    $lockMock->expects($this->once())
        ->method('acquire')
        ->willThrowException(new LockStorageException('Redis connection refused'));
    $lockMock->expects($this->never())->method('release'); // Pas de release en mode dégradé

    $lockFactory = $this->createMock(LockFactory::class);
    $lockFactory->method('createLock')->willReturn($lockMock);

    // Le sélecteur DOIT être appelé malgré le Redis KO (résilience)
    $selectorMock = $this->createMock(BriefSelectorServiceInterface::class);
    $selectorMock->expects($this->once())->method('selectTopStories')->willReturn(null);

    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->expects($this->never())->method('dispatch');

    $loggedWarnings = [];
    $loggedInfos = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('warning')->willReturnCallback(
        static function (string $msg, array $ctx = []) use (&$loggedWarnings): void {
            $loggedWarnings[] = $ctx['event'] ?? $msg;
        },
    );
    $logger->method('info')->willReturnCallback(
        static function (string $msg, array $ctx = []) use (&$loggedInfos): void {
            $loggedInfos[] = $ctx['event'] ?? $msg;
        },
    );

    $featuredSummaryService = $this->createMock(FeaturedSummaryServiceInterface::class);
    $dailyBriefRepository = $this->createMock(DailyBriefRepositoryInterface::class);
    $dailyBriefRepository->method('findForDate')->willReturn(null); // skip featured summary
    $briefPublicViewRepository = $this->createMock(BriefPublicViewRepositoryInterface::class);

    $handler = new GenerateDailyBriefHandler($selectorMock, $dispatcher, $logger, $lockFactory, $featuredSummaryService, $dailyBriefRepository, $briefPublicViewRepository);

    // Mode dégradé = pas d'exception levée
    expect(static fn () => $handler(new GenerateDailyBriefMessage('2026-07-28')))
        ->not->toThrow(Throwable::class);

    expect($loggedWarnings)->toContain('brief.lock_unavailable');
    expect($loggedInfos)->toContain('brief.batch_success');
})->group('handler', 'lock');

// ── T-003-07 : Erreur technique → exception propagée + lock libéré (finally) ─

test('Erreur technique : exception propagée pour retry Messenger + lock libéré dans finally', function (): void {
    $lockMock = $this->createMock(SharedLockInterface::class);
    $lockMock->expects($this->once())->method('acquire')->with(false)->willReturn(true);
    $lockMock->expects($this->once())->method('release'); // Libéré dans le bloc finally

    $lockFactory = $this->createMock(LockFactory::class);
    $lockFactory->method('createLock')->willReturn($lockMock);

    $dbException = new RuntimeException('Database query timeout');

    $selectorMock = $this->createMock(BriefSelectorServiceInterface::class);
    $selectorMock->method('selectTopStories')->willThrowException($dbException);

    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->expects($this->never())->method('dispatch');

    $loggedErrors = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('error')->willReturnCallback(
        static function (string $msg, array $ctx = []) use (&$loggedErrors): void {
            $loggedErrors[] = $ctx['event'] ?? $msg;
        },
    );

    $featuredSummaryService = $this->createMock(FeaturedSummaryServiceInterface::class);
    $dailyBriefRepository = $this->createMock(DailyBriefRepositoryInterface::class);
    $dailyBriefRepository->method('findForDate')->willReturn(null); // skip featured summary
    $briefPublicViewRepository = $this->createMock(BriefPublicViewRepositoryInterface::class);

    $handler = new GenerateDailyBriefHandler($selectorMock, $dispatcher, $logger, $lockFactory, $featuredSummaryService, $dailyBriefRepository, $briefPublicViewRepository);

    // L'exception DOIT être propagée pour que Messenger marque le message "failed" → retry
    expect(static fn () => $handler(new GenerateDailyBriefMessage('2026-07-28')))
        ->toThrow(RuntimeException::class, 'Database query timeout');

    expect($loggedErrors)->toContain('brief.batch_failed');
})->group('handler', 'lock');

// ── T-003-07 : brief.batch_success contient duration_ms et date ──────────────

test('brief.batch_success contient duration_ms (entier) et la date cible', function (): void {
    $lockMock = $this->createMock(SharedLockInterface::class);
    $lockMock->method('acquire')->willReturn(true);
    $lockMock->method('release');

    $lockFactory = $this->createMock(LockFactory::class);
    $lockFactory->method('createLock')->willReturn($lockMock);

    $selectorMock = $this->createMock(BriefSelectorServiceInterface::class);
    $selectorMock->method('selectTopStories')->willReturn(null);

    $dispatcher = $this->createMock(EventDispatcherInterface::class);

    $successContexts = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('info')->willReturnCallback(
        static function (string $msg, array $ctx = []) use (&$successContexts): void {
            if (($ctx['event'] ?? '') === 'brief.batch_success') {
                $successContexts[] = $ctx;
            }
        },
    );

    $featuredSummaryService = $this->createMock(FeaturedSummaryServiceInterface::class);
    $dailyBriefRepository = $this->createMock(DailyBriefRepositoryInterface::class);
    $dailyBriefRepository->method('findForDate')->willReturn(null); // skip featured summary
    $briefPublicViewRepository = $this->createMock(BriefPublicViewRepositoryInterface::class);

    $handler = new GenerateDailyBriefHandler($selectorMock, $dispatcher, $logger, $lockFactory, $featuredSummaryService, $dailyBriefRepository, $briefPublicViewRepository);
    $handler(new GenerateDailyBriefMessage('2026-07-28'));

    expect($successContexts)->toHaveCount(1, 'Un seul log brief.batch_success attendu');
    $ctx = $successContexts[0];
    expect($ctx)->toHaveKey('duration_ms');
    expect($ctx['duration_ms'])->toBeInt('duration_ms doit être un entier');
    expect($ctx['duration_ms'])->toBeGreaterThanOrEqual(0);
    expect($ctx['date'])->toBe('2026-07-28', 'La date doit correspondre au message');
})->group('handler', 'lock');
