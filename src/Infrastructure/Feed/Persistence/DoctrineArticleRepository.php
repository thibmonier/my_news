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
     * et retourne false (0 ligne affectée).
     */
    public function saveIgnoringDuplicate(ArticleDTO $dto): bool
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
            return false;
        }

        return $affected > 0;
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
