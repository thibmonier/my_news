<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration US-002 — Création des tables `daily_briefs` et `brief_stories`.
 *
 * daily_briefs :
 *   - id         : UUID (v4, généré en PHP)
 *   - date       : DATE NOT NULL UNIQUE (un brief par jour)
 *   - status     : VARCHAR(10) ENUM(pending, ready, error)
 *   - updated_at : TIMESTAMPTZ — date de dernière mise à jour (idempotence)
 *
 * brief_stories :
 *   - id               : UUID
 *   - brief_id         : UUID FK → daily_briefs.id (CASCADE DELETE)
 *   - article_id       : UUID (référence soft vers articles.id, pas de FK stricte)
 *   - position         : SMALLINT 1–3
 *   - selection_score  : FLOAT — score composite (analytics EPIC-008)
 *   - UNIQUE (brief_id, position)
 *
 * Index :
 *   - idx_daily_briefs_date   (daily_briefs.date)
 *   - idx_daily_briefs_status (daily_briefs.status)
 *   - idx_brief_stories_article_id (brief_stories.article_id)
 *
 * Convention : jamais modifier une migration existante (doctrine_migrations.yaml).
 * Rollback : down() supprime les tables dans l'ordre inverse (FK brief_id).
 */
final class Version20260728120003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-002 : Création tables daily_briefs + brief_stories (sélection algorithmique)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE daily_briefs (
                id         UUID                        NOT NULL,
                date       DATE                        NOT NULL,
                status     VARCHAR(10)                 NOT NULL DEFAULT 'pending',
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT daily_briefs_date_unique UNIQUE (date)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_daily_briefs_date ON daily_briefs (date DESC)');
        $this->addSql('CREATE INDEX idx_daily_briefs_status ON daily_briefs (status)');

        $this->addSql("COMMENT ON COLUMN daily_briefs.status IS '(DC2Type:App\\\\Domain\\\\Brief\\\\DailyBriefStatus)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE brief_stories (
                id               UUID            NOT NULL,
                brief_id         UUID            NOT NULL,
                article_id       UUID            NOT NULL,
                position         SMALLINT        NOT NULL,
                selection_score  DOUBLE PRECISION NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                CONSTRAINT fk_brief_stories_brief FOREIGN KEY (brief_id)
                    REFERENCES daily_briefs (id) ON DELETE CASCADE,
                CONSTRAINT brief_stories_brief_position_unique UNIQUE (brief_id, position),
                CONSTRAINT brief_stories_position_check CHECK (position >= 1 AND position <= 3)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_brief_stories_article_id ON brief_stories (article_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE brief_stories');
        $this->addSql('DROP TABLE daily_briefs');
    }
}
