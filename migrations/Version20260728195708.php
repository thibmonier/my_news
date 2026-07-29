<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration US-020 — Seed : 3 sources RSS actives pour le Walking Skeleton.
 *
 * Sources configurées en conversation US-020 :
 * - TechCrunch   : https://techcrunch.com/feed/
 * - The Verge    : https://www.theverge.com/rss/index.xml
 * - Ars Technica : https://feeds.arstechnica.com/arstechnica/index
 *
 * UUIDs fixes (v4) pour reproductibilité des environnements dev/CI.
 * Convention : jamais modifier une migration existante.
 */
final class Version20260728195708 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-020 : Seed des 3 sources RSS Walking Skeleton (TechCrunch, The Verge, Ars Technica)';
    }

    public function up(Schema $schema): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');

        $sources = [
            [
                'id'        => 'a1b2c3d4-e5f6-4789-8abc-def012345601',
                'name'      => 'TechCrunch',
                'url'       => 'https://techcrunch.com/feed/',
                'feed_type' => 'rss',
            ],
            [
                'id'        => 'a1b2c3d4-e5f6-4789-8abc-def012345602',
                'name'      => 'The Verge',
                'url'       => 'https://www.theverge.com/rss/index.xml',
                'feed_type' => 'rss',
            ],
            [
                'id'        => 'a1b2c3d4-e5f6-4789-8abc-def012345603',
                'name'      => 'Ars Technica',
                'url'       => 'https://feeds.arstechnica.com/arstechnica/index',
                'feed_type' => 'rss',
            ],
        ];

        foreach ($sources as $source) {
            $this->addSql(
                <<<'SQL'
                    INSERT INTO sources (id, name, url, feed_type, status, created_at)
                    VALUES (:id, :name, :url, :feed_type, 'active', :created_at)
                    ON CONFLICT (id) DO NOTHING
                    SQL,
                [
                    'id'         => $source['id'],
                    'name'       => $source['name'],
                    'url'        => $source['url'],
                    'feed_type'  => $source['feed_type'],
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE FROM sources WHERE id IN (
                'a1b2c3d4-e5f6-4789-8abc-def012345601',
                'a1b2c3d4-e5f6-4789-8abc-def012345602',
                'a1b2c3d4-e5f6-4789-8abc-def012345603'
            )"
        );
    }
}
