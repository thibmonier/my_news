<?php

declare(strict_types=1);

namespace App\Application\Brief\GenerateDailyBrief;

use App\Application\Brief\FeaturedSummary\FeaturedSummaryServiceInterface;
use App\Domain\Brief\BriefPublicViewRepositoryInterface;
use App\Domain\Brief\BriefSelectorServiceInterface;
use App\Domain\Brief\DailyBriefRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler Messenger — Orchestre la génération du Daily Brief (US-002 + US-003 + US-006).
 *
 * Reçoit GenerateDailyBriefMessage, acquiert un lock Redis (anti-doublon),
 * délègue au BriefSelectorService, dispatche BriefGenerationFailedEvent si aucun article,
 * puis génère la synthèse narrative Featured Summary (US-006).
 *
 * Lock Redis (US-003/T-003-02) :
 * - Clé : "briefly.daily_brief_generation", TTL 600s
 * - TryLock non-bloquant (acquire(false)) : si lock déjà acquis → log INFO + skip
 * - Redis KO (LockStorageException) → log WARNING + mode dégradé (exécution sans lock)
 *
 * Logging structuré JSON (US-003/T-003-04) :
 * - brief.batch_start           : INFO  au démarrage
 * - brief.batch_success         : INFO  + duration_ms si succès
 * - brief.batch_failed          : ERROR si BriefSelectorService retourne un événement d'échec
 * - brief.lock_already_acquired : INFO si lock non acquis (doublon détecté → skip)
 * - brief.lock_unavailable      : WARNING si Redis KO (mode dégradé)
 * - featured_summary.generation_failed : WARNING si FeaturedSummaryService KO (non-bloquant)
 *
 * Retry : Messenger gère les retries (max 3 tentatives, backoff exponentiel 5 min, 10 min, 20 min).
 * Les exceptions techniques (timeout DB, etc.) se propagent pour déclencher le retry.
 *
 * SÉCURITÉ OWASP #7 : pas de stack trace dans les logs, messages d'erreur génériques.
 * RGPD : aucune donnée personnelle dans les messages et logs (dateTarget uniquement).
 *
 * Deptrac Application:[Domain] — dépend de BriefSelectorService (Domain) et interfaces Symfony.
 */
#[AsMessageHandler]
final class GenerateDailyBriefHandler
{
    private const LOCK_KEY = 'briefly.daily_brief_generation';
    private const LOCK_TTL = 600.0; // 10 minutes

    public function __construct(
        private readonly BriefSelectorServiceInterface $briefSelectorService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
        private readonly FeaturedSummaryServiceInterface $featuredSummaryService,
        private readonly DailyBriefRepositoryInterface $dailyBriefRepository,
        private readonly BriefPublicViewRepositoryInterface $briefPublicViewRepository,
    ) {
    }

    /**
     * Génère le Daily Brief pour la date cible du message.
     *
     * @throws \Throwable les exceptions techniques (timeout DB, etc.) se propagent
     *                    pour que Messenger déclenche le retry automatique
     */
    public function __invoke(GenerateDailyBriefMessage $message): void
    {
        $date = $message->getDate();
        $startTime = microtime(true);

        $this->logger->info('brief.batch_start', [
            'event' => 'brief.batch_start',
            'date' => $date->format('Y-m-d'),
        ]);

        // ── Acquisition du lock Redis (TryLock non-bloquant) ─────────────────
        $lock = $this->lockFactory->createLock(self::LOCK_KEY, self::LOCK_TTL);
        $lockAcquired = false;
        $degradedMode = false;

        try {
            $lockAcquired = $lock->acquire(false); // Non-bloquant : retourne bool
            // LockStorageException CAN be thrown by concrete implementations (e.g. Redis KO),
            // even though SharedLockInterface::acquire() does not declare @throws LockStorageException.
            // @phpstan-ignore catch.neverThrown
        } catch (LockStorageException $e) {
            // Redis indisponible → mode dégradé (US-003 scénario erreur 1)
            $this->logger->warning('brief.lock_unavailable', [
                'event' => 'brief.lock_unavailable',
                'action' => 'proceeding_without_lock',
                'date' => $date->format('Y-m-d'),
                // Pas de trace stack (OWASP #7)
            ]);
            $degradedMode = true;
        }

        // Si le lock est déjà tenu par une autre instance → skip silencieux
        // @phpstan-ignore booleanNot.alwaysTrue ($degradedMode can be true at runtime if Redis KO, see catch above)
        if (!$lockAcquired && !$degradedMode) {
            $this->logger->info('brief.lock_already_acquired', [
                'event' => 'brief.lock_already_acquired',
                'action' => 'skipped',
                'date' => $date->format('Y-m-d'),
            ]);

            return;
        }

        // ── Exécution principale (avec ou sans lock selon le mode) ────────────
        try {
            $failedEvent = $this->briefSelectorService->selectTopStories($date);

            if (null !== $failedEvent) {
                // 0 articles disponibles — brief J-1 reste intact et consultable (US-001)
                $this->eventDispatcher->dispatch($failedEvent);

                $this->logger->error('brief.batch_failed', [
                    'event' => 'brief.batch_failed',
                    'date' => $date->format('Y-m-d'),
                    'reason' => $failedEvent->reason,
                ]);

                return;
            }

            // ── US-006 : Génération du Featured Summary après sélection des stories ─
            try {
                $dailyBrief = $this->dailyBriefRepository->findForDate($date);
                $publicView = $this->briefPublicViewRepository->findLatestPublicView();

                if (null !== $dailyBrief && null !== $publicView && [] !== $publicView->stories) {
                    $this->featuredSummaryService->generateForBrief(
                        briefId: $dailyBrief->getId(),
                        date: $date,
                        stories: $publicView->stories,
                    );
                }
            } catch (\Throwable $e) {
                // Non-bloquant : une erreur sur le Featured Summary ne doit pas
                // annuler la génération du brief principal (US-006 scénario fallback)
                $this->logger->warning('featured_summary.generation_failed', [
                    'event' => 'featured_summary.generation_failed',
                    'date' => $date->format('Y-m-d'),
                    'error' => $e->getMessage(),
                ]);
            }

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logger->info('brief.batch_success', [
                'event' => 'brief.batch_success',
                'duration_ms' => $durationMs,
                'date' => $date->format('Y-m-d'),
            ]);
        } catch (\Throwable $e) {
            // Erreur technique (timeout DB, etc.) : log sans stack trace, puis propage
            // pour que Messenger marque le message "failed" → retry (max 3 tentatives)
            // US-003 scénario erreur 2 : brief.max_retries_exceeded géré par Messenger DLQ
            $this->logger->error('brief.batch_failed', [
                'event' => 'brief.batch_failed',
                'date' => $date->format('Y-m-d'),
                'error_class' => $e::class,
                'error' => $e->getMessage(),
                // Pas de stacktrace complète en prod (OWASP #7 Mishandling Exceptional Conditions)
            ]);

            throw $e; // Propage pour retry Messenger
        } finally {
            // Libération du lock si acquis (pas en mode dégradé)
            if ($lockAcquired) {
                $lock->release();
            }
        }
    }
}
