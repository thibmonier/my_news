<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration initiale — Sprint 1 Walking Skeleton (US-001 à US-033).
 *
 * Crée toutes les tables nécessaires au Sprint 1 :
 * - users          (US-030 : inscription + authentification)
 * - sources        (US-020 : ingestion RSS/Atom)
 * - articles       (US-020 : articles ingérés, déduplication SHA-256)
 * - daily_briefs   (US-002 : Daily Brief quotidien)
 * - brief_stories  (US-002 : histoires sélectionnées par BriefSelectorService)
 *
 * Convention : jamais modifier une migration existante — toujours créer une nouvelle.
 * Rollback possible via down() (tech-spec §15.3).
 *
 * Sécurité :
 * - UUID v4 (non séquentiel — constitution §6 + ADR-006)
 * - Argon2id via libsodium pour les mots de passe (column password_hash)
 * - Index UNIQUE sur content_hash (SHA-256 — US-020 fingerprint déduplication)
 */
final class Version20260728195706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint 1 Walking Skeleton — tables users, sources, articles, daily_briefs, brief_stories';
    }

    public function up(Schema $schema): void
    {
        // ── users ──────────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id            UUID        NOT NULL,
                email         VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                full_name     VARCHAR(255) NOT NULL,
                roles         JSON        NOT NULL DEFAULT '["ROLE_USER"]',
                created_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                consent_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT users_email_unique UNIQUE (email)
            )
            SQL);

        $this->addSql('COMMENT ON COLUMN users.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.consent_at IS \'(DC2Type:datetime_immutable)\'');

        // ── sources ───────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE sources (
                id              UUID         NOT NULL,
                name            VARCHAR(255) NOT NULL,
                url             VARCHAR(2048) NOT NULL,
                feed_type       VARCHAR(10)  NOT NULL,
                status          VARCHAR(20)  NOT NULL,
                last_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_error_at   TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                etag            VARCHAR(255) DEFAULT NULL,
                last_modified   VARCHAR(255) DEFAULT NULL,
                created_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('COMMENT ON COLUMN sources.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN sources.last_fetched_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN sources.last_error_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN sources.created_at IS \'(DC2Type:datetime_immutable)\'');

        // ── articles ──────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE articles (
                id                    UUID          NOT NULL,
                source_id             UUID          NOT NULL,
                title                 VARCHAR(1024) NOT NULL,
                url                   VARCHAR(2048) NOT NULL,
                content_hash          VARCHAR(64)   NOT NULL,
                published_at          TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                raw_content           TEXT          NOT NULL,
                fetch_at              TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                cluster_id            VARCHAR(64)   DEFAULT NULL,
                is_full_text_accessible BOOLEAN     NOT NULL DEFAULT TRUE,
                PRIMARY KEY(id),
                CONSTRAINT articles_content_hash_unique UNIQUE (content_hash)
            )
            SQL);

        $this->addSql('COMMENT ON COLUMN articles.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN articles.source_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN articles.published_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN articles.fetch_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_articles_published_at ON articles (published_at)');
        $this->addSql('CREATE INDEX idx_articles_source_id ON articles (source_id)');

        // ── daily_briefs ──────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE daily_briefs (
                id         UUID        NOT NULL,
                date       DATE        NOT NULL,
                status     VARCHAR(10) NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT daily_briefs_date_unique UNIQUE (date)
            )
            SQL);

        $this->addSql('COMMENT ON COLUMN daily_briefs.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN daily_briefs.date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN daily_briefs.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_daily_briefs_date ON daily_briefs (date)');
        $this->addSql('CREATE INDEX idx_daily_briefs_status ON daily_briefs (status)');

        // ── brief_stories ─────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE brief_stories (
                id              UUID     NOT NULL,
                brief_id        UUID     NOT NULL,
                article_id      UUID     NOT NULL,
                position        SMALLINT NOT NULL,
                selection_score DOUBLE PRECISION NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT brief_stories_brief_position_unique UNIQUE (brief_id, position),
                CONSTRAINT fk_brief_stories_brief_id
                    FOREIGN KEY (brief_id) REFERENCES daily_briefs (id) ON DELETE CASCADE
            )
            SQL);

        $this->addSql('COMMENT ON COLUMN brief_stories.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN brief_stories.brief_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN brief_stories.article_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE INDEX idx_brief_stories_article_id ON brief_stories (article_id)');
    }

    public function down(Schema $schema): void
    {
        // Rollback dans l'ordre inverse des FK
        $this->addSql('DROP TABLE brief_stories');
        $this->addSql('DROP TABLE daily_briefs');
        $this->addSql('DROP TABLE articles');
        $this->addSql('DROP TABLE sources');
        $this->addSql('DROP TABLE users');
    }
}
