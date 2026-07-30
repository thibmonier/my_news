<?php

declare(strict_types=1);

namespace App\Infrastructure\Brief\Persistence;

use App\Domain\Brief\ArticleCandidateRepositoryInterface;
use App\Domain\Feed\Article;
use App\Domain\Feed\ContentHash;
use Doctrine\DBAL\Connection;

/**
 * Adapter DBAL — Implémentation de ArticleCandidateRepositoryInterface.
 *
 * Lecture des articles candidats pour la sélection du brief.
 * Utilise DBAL natif (pas ORM) pour la performance sur les grandes tables.
 *
 * SÉCURITÉ :
 * - Aucune donnée personnelle dans les queries (articles = données éditoriales)
 * - Limite SQL (200 articles max) pour borner la mémoire et le temps d'exécution
 * - Query paramétrée uniquement (prévention injection SQL)
 *
 * Couche Infrastructure : dépend de DBAL et du Domain.
 * Deptrac : Infrastructure:[Domain, Application].
 */
final class DoctrineArticleCandidateRepository implements ArticleCandidateRepositoryInterface
{
    /** Nombre maximum d'articles chargés en mémoire par sélection. */
    private const MAX_CANDIDATES = 200;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<Article>
     */
    public function findCandidatesForBrief(\DateTimeImmutable $since): array
    {
        /** @var list<array{id: string, source_id: string, title: string, url: string, content_hash: non-empty-string, published_at: string, raw_content: string|null, fetch_at: string, cluster_id: string|null, is_full_text_accessible: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    a.id,
                    a.source_id,
                    a.title,
                    a.url,
                    a.content_hash,
                    a.published_at,
                    a.raw_content,
                    a.fetch_at,
                    a.cluster_id,
                    a.is_full_text_accessible
                FROM articles a
                WHERE a.published_at >= :since
                  AND a.is_full_text_accessible = TRUE
                  AND a.is_duplicate = FALSE
                ORDER BY a.published_at DESC, a.id ASC
                LIMIT :limit
                SQL,
            [
                'since' => $since->format('Y-m-d H:i:sP'),
                'limit' => self::MAX_CANDIDATES,
            ],
        );

        return array_map(
            static function (array $row): Article {
                return new Article(
                    id: $row['id'],
                    sourceId: $row['source_id'],
                    title: $row['title'],
                    url: $row['url'],
                    contentHash: ContentHash::fromStoredHash($row['content_hash']),
                    publishedAt: new \DateTimeImmutable($row['published_at']),
                    rawContent: $row['raw_content'] ?? '',
                    fetchAt: new \DateTimeImmutable($row['fetch_at']),
                    clusterId: $row['cluster_id'] ?? null,
                    isFullTextAccessible: (bool) $row['is_full_text_accessible'],
                );
            },
            $rows,
        );
    }
}
