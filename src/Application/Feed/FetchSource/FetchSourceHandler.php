<?php

declare(strict_types=1);

namespace App\Application\Feed\FetchSource;

use App\Domain\Feed\ArticleRepositoryInterface;
use App\Domain\Feed\SourceFetcherInterface;
use App\Domain\Feed\SourceRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler Messenger — Ingère le flux RSS/Atom d'une source unique.
 *
 * Traitement par source (un message = une source) pour parallélisation horizontale.
 * Les erreurs par source sont catchées, loguées et n'interrompent pas les autres workers.
 *
 * Respecte l'architecture hexagonale :
 * - Dépend uniquement d'interfaces Domain et de Psr\Log
 * - Aucune dépendance Doctrine, FeedIo, Redis (deptrac : Application:[Domain])
 *
 * Scénarios couverts :
 * - Nominal : articles parsés → insérés (ON CONFLICT DO NOTHING) → last_fetched_at mis à jour
 * - HTTP 5xx / XML invalide : exception catchée, last_error_at mis à jour, worker libéré
 */
#[AsMessageHandler]
final class FetchSourceHandler
{
    public function __construct(
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly SourceFetcherInterface $sourceFetcher,
        private readonly ArticleRepositoryInterface $articleRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \RuntimeException ne propage jamais les exceptions au transport Messenger
     */
    public function __invoke(FetchSourceMessage $message): void
    {
        $source = $this->sourceRepository->findById($message->sourceId);

        if (null === $source) {
            $this->logger->warning('FetchSourceHandler: source introuvable', [
                'source_id' => $message->sourceId,
            ]);

            return;
        }

        try {
            $articles = $this->sourceFetcher->fetch($source);
            $insertedCount = 0;

            foreach ($articles as $dto) {
                if ($this->articleRepository->saveIgnoringDuplicate($dto)) {
                    ++$insertedCount;
                }
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $this->sourceRepository->updateLastFetchedAt($source->getId(), $now);

            $this->logger->info('FetchSourceHandler: ingestion terminée', [
                'source_id' => $source->getId(),
                'source_name' => $source->getName(),
                'fetched' => \count($articles),
                'inserted' => $insertedCount,
                'duplicates' => \count($articles) - $insertedCount,
                'last_fetched_at' => $now->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\Throwable $e) {
            $errorAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $this->sourceRepository->updateLastErrorAt($source->getId(), $errorAt);

            $this->logger->error('FetchSourceHandler: erreur lors de l\'ingestion', [
                'source_id' => $source->getId(),
                'source_url' => $source->getUrl(),
                'error' => $e->getMessage(),
                'error_class' => $e::class,
                'last_error_at' => $errorAt->format(\DateTimeInterface::ATOM),
            ]);
            // Ne pas propager : le worker Messenger se libère normalement
        }
    }
}
