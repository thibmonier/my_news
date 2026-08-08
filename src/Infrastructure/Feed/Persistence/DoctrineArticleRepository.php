<?php

declare(strict_types=1);

namespace App\Infrastructure\Feed\Persistence;

use App\Domain\Feed\ArticleDTO;
use App\Domain\Feed\ArticleRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter Doctrine/DBAL — Implémentation de ArticleRepositoryInterface.
 *
 * Utilise DBAL natif pour INSERT … ON CONFLICT DO NOTHING (PostgreSQL 16)
 * afin de garantir l'idempotence de l'ingestion (déduplication SHA-256).
 *
 * US-022 : méthodes SimHash ajoutées (findPotentialDuplicates, markAsDuplicate, updateTitleSimHash).
 * Requêtes SQL : BIT_COUNT((title_simhash # :simhash)::bit(64)) — PostgreSQL 14+.
 *
 * Couche Infrastructure : dépend de DBAL et du Domain.
 * Deptrac : Infrastructure:[Domain, Application].
 */
final class DoctrineArticleRepository implements ArticleRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * INSERT … ON CONFLICT (content_hash) DO NOTHING.
     *
     * Idempotent : un second appel avec le même content_hash ne lève aucune exception
     * et retourne null (0 ligne affectée).
     *
     * @return string|null UUID de l'article inséré, null si doublon SHA-256
     */
    public function saveIgnoringDuplicate(ArticleDTO $dto): ?string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = Uuid::v4()->toRfc4122();

        try {
            $affected = $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO articles (
                        id, source_id, title, url, content_hash,
                        published_at, raw_content, fetch_at,
                        cluster_id, is_full_text_accessible
                    ) VALUES (
                        :id, :source_id, :title, :url, :content_hash,
                        :published_at, :raw_content, :fetch_at,
                        NULL, TRUE
                    )
                    ON CONFLICT (content_hash) DO NOTHING
                    SQL,
                [
                    'id' => $id,
                    'source_id' => $dto->sourceId,
                    'title' => mb_substr($dto->title, 0, 1024),
                    'url' => mb_substr($dto->url, 0, 2048),
                    'content_hash' => $dto->contentHash->getValue(),
                    'published_at' => $dto->publishedAt->format('Y-m-d H:i:sP'),
                    'raw_content' => $dto->rawContent,
                    'fetch_at' => $now->format('Y-m-d H:i:sP'),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // Race condition rare : deux workers traitant la même source simultanément
            return null;
        }

        return $affected > 0 ? $id : null;
    }

    /**
     * Recherche les articles non-dupliqués avec un SimHash proche dans une fenêtre ±2h.
     *
     * SQL : BIT_COUNT((title_simhash # :simhash)::bit(64)) <= :threshold
     * Filtre temporel : ABS(EXTRACT(EPOCH FROM published_at - :pub)) <= 7200
     * Fenêtre = ±2 heures = 7200 secondes.
     *
     * Retourne au plus 1 résultat (le plus récent) — le premier doublon trouvé suffit.
     *
     * @return list<array{id: string, title: string, simhash: int}>
     */
    public function findPotentialDuplicates(int $simhash, \DateTimeImmutable $publishedAt, int $threshold): array
    {
        /** @var list<array{id: string, title: string, title_simhash: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT a.id, a.title, a.title_simhash
                FROM articles a
                WHERE a.is_duplicate = FALSE
                  AND a.title_simhash IS NOT NULL
                  AND bit_count((a.title_simhash # :simhash::bigint)::bit(64)) <= :threshold
                  AND ABS(EXTRACT(EPOCH FROM a.published_at - :pub::timestamp with time zone)) <= 7200
                ORDER BY a.published_at DESC
                LIMIT 1
                SQL,
            [
                'simhash' => (string) $simhash,
                'threshold' => $threshold,
                'pub' => $publishedAt->format('Y-m-d H:i:sP'),
            ],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'title' => $row['title'],
                'simhash' => (int) $row['title_simhash'],
            ],
            $rows,
        );
    }

    /**
     * Marque un article comme doublon sémantique.
     *
     * Met à jour : title_simhash, is_duplicate = TRUE, duplicate_of = $duplicateOfId.
     */
    public function markAsDuplicate(string $articleId, int $simhash, string $duplicateOfId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE articles
                SET title_simhash = :simhash,
                    is_duplicate  = TRUE,
                    duplicate_of  = :duplicate_of
                WHERE id = :id
                SQL,
            [
                'simhash' => (string) $simhash,
                'duplicate_of' => $duplicateOfId,
                'id' => $articleId,
            ],
        );
    }

    /**
     * Met à jour uniquement le title_simhash d'un article non-doublon.
     */
    public function updateTitleSimHash(string $articleId, int $simhash): void
    {
        $this->connection->executeStatement(
            'UPDATE articles SET title_simhash = :simhash WHERE id = :id',
            [
                'simhash' => $simhash,
                'id' => $articleId,
            ],
        );
    }

    /**
     * @return list<array{id: string, title: string, url: string, contentHash: string, publishedAt: \DateTimeImmutable, sourceName: string}>
     */
    public function findPaginatedWithSourceName(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        /** @var list<array{id: string, title: string, url: string, content_hash: string, published_at: string, source_name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT a.id, a.title, a.url, a.content_hash, a.published_at, s.name AS source_name
                FROM articles a
                JOIN sources s ON s.id = a.source_id
                ORDER BY a.published_at DESC
                LIMIT :limit OFFSET :offset
                SQL,
            [
                'limit' => $perPage,
                'offset' => $offset,
            ],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'title' => $row['title'],
                'url' => $row['url'],
                'contentHash' => $row['content_hash'],
                'publishedAt' => new \DateTimeImmutable($row['published_at']),
                'sourceName' => $row['source_name'],
            ],
            $rows,
        );
    }

    public function findUrlById(string $id): ?string
    {
        $url = $this->connection->fetchOne(
            'SELECT url FROM articles WHERE id = :id',
            ['id' => $id],
        );

        return is_string($url) ? $url : null;
    }

    /** @return non-negative-int */
    public function countAll(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM articles');
        // fetchOne returns mixed; COUNT(*) always yields a non-negative integer.
        $result = is_numeric($count) ? (int) $count : 0;
        if ($result < 0) {
            return 0;
        }

        return $result;
    }
}
