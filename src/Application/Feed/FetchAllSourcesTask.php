<?php

declare(strict_types=1);

namespace App\Application\Feed;

use App\Application\Feed\FetchSource\FetchSourceMessage;
use App\Domain\Feed\SourceRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Tâche planifiée — Publie un FetchSourceMessage par source active.
 *
 * Déclenchée toutes les 15 minutes par le Symfony Scheduler (worker `messenger:consume scheduler_default`).
 * Peut aussi être invoquée manuellement via la console `briefly:fetch-all-sources`.
 *
 * Respecte l'architecture hexagonale :
 * - Dépend des interfaces Domain + Messenger (Application layer)
 * - Aucune dépendance Doctrine, FeedIo, Redis directe
 * - Deptrac : Application:[Domain]
 *
 * Note : MessageBusInterface est une abstraction Symfony — acceptable en Application
 * car c'est le mécanisme de dispatch, pas une dépendance Infrastructure concrète.
 */
#[AsPeriodicTask(frequency: '15 minutes', schedule: 'default')]
final class FetchAllSourcesTask
{
    public function __construct(
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Publie un FetchSourceMessage dans la queue `async` pour chaque source active.
     * Le traitement parallèle est assuré par N workers Messenger.
     */
    public function __invoke(): void
    {
        $sources = $this->sourceRepository->findAllActive();

        foreach ($sources as $source) {
            $this->messageBus->dispatch(new FetchSourceMessage($source->getId()));
        }
    }
}
