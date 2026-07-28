<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration US-020 — Création des tables `sources` et `articles`.
 *
 * - Table `sources` : sources RSS/Atom avec status, etag, last_fetched_at, last_error_at
 * - Table `articles` : articles ingérés avec contrainte UNIQUE sur content_hash (SHA-256)
 * - Index sur articles.published_at (tri liste admin + génération de briefs)
 * - Index sur articles.source_id (jointures)
 *
 * Rollback : down() supprime les tables dans l'ordre inverse (FK source_id).
 * Convention : jamais modifier une migration existante (doctrine_migrations.yaml).
 */
final class Version20260728120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-020 : Création tables sources et articles (pipeline RSS Walking Skeleton)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sources (
                id          UUID            NOT NULL,
                name        VARCHAR(255)    NOT NULL,
                url         VARCHAR(2048)   NOT NULL,
                feed_type   VARCHAR(10)     NOT NULL,
                status      VARCHAR(20)     NOT NULL DEFAULT 'active',
                last_fetched_at  TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                last_error_at    TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                etag             VARCHAR(255) DEFAULT NULL,
                last_modified    VARCHAR(255) DEFAULT NULL,
                created_at       TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE articles (
                id                       UUID            NOT NULL,
                source_id                UUID            NOT NULL,
                title                    VARCHAR(1024)   NOT NULL,
                url                      VARCHAR(2048)   NOT NULL,
                content_hash             VARCHAR(64)     NOT NULL,
                published_at             TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                raw_content              TEXT            NOT NULL DEFAULT '',
                fetch_at                 TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                cluster_id               VARCHAR(64)     DEFAULT NULL,
                is_full_text_accessible  BOOLEAN         NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id),
                CONSTRAINT articles_content_hash_unique UNIQUE (content_hash),
                CONSTRAINT fk_articles_source FOREIGN KEY (source_id)
                    REFERENCES sources (id) ON DELETE CASCADE
            )
            SQL);

        $this->addSql('CREATE INDEX idx_articles_published_at ON articles (published_at DESC)');
        $this->addSql('CREATE INDEX idx_articles_source_id ON articles (source_id)');

        $this->addSql("COMMENT ON COLUMN sources.feed_type IS '(DC2Type:App\\\\Domain\\\\Feed\\\\FeedType)'");
        $this->addSql("COMMENT ON COLUMN sources.status IS '(DC2Type:App\\\\Domain\\\\Feed\\\\SourceStatus)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE articles');
        $this->addSql('DROP TABLE sources');
    }
}
