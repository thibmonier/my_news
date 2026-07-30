<?php

declare(strict_types=1);

namespace App\Application\Feed\FetchSource;

use App\Domain\Feed\ArticleDTO;
use App\Domain\Feed\ArticleRepositoryInterface;
use App\Domain\Feed\SimHashServiceInterface;
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
 * US-022 : enrichissement SimHash après insertion (barrière secondaire de déduplication).
 * - SHA-256 URL reste la barrière primaire (saveIgnoringDuplicate, inchangée)
 * - SimHash détecte les doublons sémantiques inter-sources (titres reformulés)
 * - Exception SimHash catchée → article conservé avec title_simhash=NULL, batch non bloqué
 * - Jamais de suppression d'article (traçabilité garantie)
 *
 * Scénarios couverts :
 * - Nominal : articles parsés → insérés (ON CONFLICT DO NOTHING) → SimHash calculé
 * - Doublon SHA-256 : saveIgnoringDuplicate retourne null, SimHash ignoré
 * - Doublon SimHash : article inséré avec is_duplicate=TRUE + duplicate_of=<uuid>
 * - Titre vide : SimHash null → log WARNING → article avec title_simhash=NULL
 * - Exception SimHash : log ERROR → article avec title_simhash=NULL → batch continue
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
        private readonly SimHashServiceInterface $simHashService,
        private readonly int $simhashThreshold,
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
                $insertedId = $this->articleRepository->saveIgnoringDuplicate($dto);

                if (null !== $insertedId) {
                    ++$insertedCount;
                    $this->processSimHash($insertedId, $dto);
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

    /**
     * Calcule le SimHash du titre et détecte les doublons sémantiques.
     *
     * Flux de traitement :
     * 1. compute($dto->title) dans un try/catch RuntimeException
     * 2. Si exception → log ERROR + retour (article conservé avec title_simhash=NULL)
     * 3. Si null (titre vide/stopwords) → log WARNING + retour
     * 4. Si simhash valide → findPotentialDuplicates dans fenêtre ±2h
     *    - Doublon trouvé → markAsDuplicate + log DEBUG
     *    - Aucun doublon  → updateTitleSimHash
     *
     * @param string $articleId UUID de l'article nouvellement inséré
     * @param ArticleDTO $dto DTO de l'article (title, publishedAt, sourceId…)
     */
    private function processSimHash(string $articleId, ArticleDTO $dto): void
    {
        // 1. Calcul SimHash (try/catch : ne bloque JAMAIS le batch)
        try {
            $simhash = $this->simHashService->compute($dto->title);
        } catch (\RuntimeException $e) {
            $this->logger->error('FetchSourceHandler: échec calcul SimHash', [
                'source_id' => $dto->sourceId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'title_excerpt' => mb_substr($dto->title, 0, 50, 'UTF-8'),
            ]);

            return; // Article conservé avec title_simhash=NULL, is_duplicate=FALSE
        }

        // 2. Titre vide ou uniquement stopwords
        if (null === $simhash) {
            $this->logger->warning('FetchSourceHandler: SimHash non calculé', [
                'source_id' => $dto->sourceId,
                'article_guid' => $dto->contentHash->getValue(),
                'message' => 'SimHash skipped: empty title',
            ]);

            return; // Article conservé avec title_simhash=NULL, is_duplicate=FALSE
        }

        // 3. Recherche des doublons potentiels dans fenêtre ±2h
        $duplicates = $this->articleRepository->findPotentialDuplicates(
            $simhash,
            $dto->publishedAt,
            $this->simhashThreshold,
        );

        if ([] !== $duplicates) {
            // 4a. Doublon détecté → marquer l'article comme doublon
            $original = $duplicates[0];
            $this->articleRepository->markAsDuplicate($articleId, $simhash, $original['id']);

            $distance = $this->simHashService->distance($simhash, $original['simhash']);

            $this->logger->debug('FetchSourceHandler: doublon SimHash détecté', [
                'title_a' => $original['title'],
                'title_b' => $dto->title,
                'distance' => $distance,
                'source_id_new' => $dto->sourceId,
                'duplicate_of' => $original['id'],
            ]);
        } else {
            // 4b. Pas de doublon → enregistrer le SimHash pour de futurs contrôles
            $this->articleRepository->updateTitleSimHash($articleId, $simhash);
        }
    }
}
