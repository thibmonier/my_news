<?php

declare(strict_types=1);

namespace App\Application\Feed\BulkFetch;

use App\Application\Feed\FetchSource\FetchSourceMessage;
use App\Domain\Feed\SourceRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handler Messenger — Publie un FetchSourceMessage par source active.
 *
 * Reçoit BulkFetchMessage, itère findAllActive(), publie un FetchSourceMessage
 * par source dans la queue async. Pas de blocage UI : l'admin reçoit un flash
 * "N sources mises en file" immédiatement.
 *
 * Deptrac Application:[Domain] — dépend de MessageBusInterface (port Messenger).
 */
#[AsMessageHandler]
final class BulkFetchHandler
{
    public function __construct(
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(BulkFetchMessage $message): void
    {
        $sources = $this->sourceRepository->findAllActive();
        $count = \count($sources);

        foreach ($sources as $source) {
            $this->messageBus->dispatch(new FetchSourceMessage($source->getId()));
        }

        $this->logger->info('BulkFetchHandler: sources enfilées pour mise à jour', [
            'count' => $count,
        ]);
    }
}
