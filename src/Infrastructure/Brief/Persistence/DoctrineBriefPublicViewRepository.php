<?php

declare(strict_types=1);

namespace App\Infrastructure\Brief\Persistence;

use App\Domain\Brief\BriefPublicView;
use App\Domain\Brief\BriefPublicViewRepositoryInterface;
use App\Domain\Brief\BriefStoryPublicView;
use App\Domain\Feed\ArticleCategory;
use App\Domain\Feed\InvalidCategoryException;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Adapter DBAL — Implémentation de BriefPublicViewRepositoryInterface (US-001, US-005).
 *
 * Requête unique avec JOIN pour éviter les N+1 queries :
 * daily_briefs → brief_stories → articles → sources.
 *
 * US-005 : ajout de a.category dans le SELECT.
 * Si la valeur de category n'est pas dans l'enum (InvalidCategoryException) :
 * - La BriefStory concernée est exclue du brief
 * - Les 2 autres stories restent affichées normalement
 * - Un log ERROR est enregistré avec article_id (UUID) et la valeur invalide (sans PII)
 *
 * Performances :
 * - Sous-requête pour sélectionner l'id du brief le plus récent (index date DESC)
 * - Limitation à 3 stories (invariant INV-1)
 * - Troncature excerpt à 280 chars côté SQL (LEFT() PostgreSQL)
 *
 * SÉCURITÉ :
 * - Requêtes paramétrées uniquement (prévention injection SQL — OWASP #3)
 * - Aucune donnée personnelle dans cette requête (articles = données éditoriales)
 *
 * Couche Infrastructure : dépend de DBAL et du Domain.
 * Deptrac : Infrastructure:[Domain, Application].
 */
final class DoctrineBriefPublicViewRepository implements BriefPublicViewRepositoryInterface
{
    private const EXCERPT_MAX_LENGTH = 280;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * Requête SQL :
     * 1. Sous-requête : id du brief le plus récent avec status = 'ready'
     * 2. JOIN brief_stories → articles → sources sur ce brief
     * 3. SELECT a.category pour le badge éditorial (US-005)
     * 4. Troncature excerpt à 280 chars (LEFT())
     * 5. Tri par position ASC
     * 6. BriefStory avec category invalide exclue (InvalidCategoryException — US-005/erreur 1)
     */
    public function findLatestPublicView(): ?BriefPublicView
    {
        /** @var list<array{updated_at: string, position: string, article_title: string, article_url: string, excerpt: string, source_name: string, article_id: string, raw_content: string, category: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    db.updated_at,
                    bs.position,
                    a.title          AS article_title,
                    a.url            AS article_url,
                    LEFT(a.raw_content, :excerpt_length) AS excerpt,
                    s.name           AS source_name,
                    CAST(a.id AS TEXT) AS article_id,
                    a.raw_content    AS raw_content,
                    a.category       AS category
                FROM daily_briefs db
                JOIN brief_stories bs ON bs.brief_id = db.id
                JOIN articles a       ON CAST(a.id AS TEXT) = CAST(bs.article_id AS TEXT)
                JOIN sources s        ON CAST(s.id AS TEXT) = CAST(a.source_id AS TEXT)
                WHERE db.id = (
                    SELECT id
                    FROM daily_briefs
                    WHERE status = 'ready'
                    ORDER BY date DESC
                    LIMIT 1
                )
                ORDER BY bs.position ASC
                LIMIT 3
                SQL,
            ['excerpt_length' => self::EXCERPT_MAX_LENGTH],
        );

        if ([] === $rows) {
            return null;
        }

        $firstRow = $rows[0];
        $updatedAt = new \DateTimeImmutable($firstRow['updated_at'], new \DateTimeZone('UTC'));

        // US-005/erreur 1 : une catégorie invalide en base exclut la BriefStory concernée.
        // Les autres stories restent affichées normalement.
        $stories = [];

        foreach ($rows as $row) {
            try {
                $category = ArticleCategory::fromDatabaseValue($row['category'], $row['article_id']);
            } catch (InvalidCategoryException $e) {
                // OWASP #9 : log ERROR sans PII — article_id (UUID) + valeur invalide uniquement
                $this->logger->error('article.invalid_category', [
                    'event' => 'article.invalid_category',
                    'article_id' => $e->articleId,
                    'invalid_value' => $e->invalidValue,
                ]);
                // La BriefStory est exclue — les autres continuent
                continue;
            }

            $stories[] = new BriefStoryPublicView(
                position: (int) $row['position'],
                articleTitle: $row['article_title'],
                articleUrl: $row['article_url'],
                excerpt: $row['excerpt'],
                sourceName: $row['source_name'],
                articleId: $row['article_id'],
                rawContent: $row['raw_content'],
                category: $category,
            );
        }

        if ([] === $stories) {
            return null;
        }

        return new BriefPublicView(
            updatedAt: $updatedAt,
            stories: $stories,
        );
    }
}
