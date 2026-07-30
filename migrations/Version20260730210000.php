<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * US-006 — Featured Summary desktop + CTA "Lire le brief complet".
 *
 * Crée la table `daily_brief_summaries` :
 *   - id            : UUID v4, PK
 *   - brief_id      : UUID NOT NULL, UNIQUE (FK logique vers daily_briefs.id)
 *   - content       : TEXT NOT NULL — texte narratif 80-120 mots (ou fallback)
 *   - model_version : VARCHAR(64) NOT NULL — 'mistral-small-latest' ou '' si fallback
 *   - generated_at  : TIMESTAMPTZ NOT NULL UTC de génération
 *   - is_fallback   : BOOLEAN NOT NULL DEFAULT FALSE — true si Mistral KO
 *
 * Index :
 *   - idx_daily_brief_summaries_generated_at : tri par date DESC (findLatest)
 *   - daily_brief_summaries_brief_id_unique  : contrainte UNIQUE sur brief_id
 */
final class Version20260730210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-006 : crée la table daily_brief_summaries pour les synthèses narratives Featured Summary.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE daily_brief_summaries (
            id UUID NOT NULL,
            brief_id UUID NOT NULL,
            content TEXT NOT NULL,
            model_version VARCHAR(64) NOT NULL,
            generated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            is_fallback BOOLEAN NOT NULL DEFAULT FALSE,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE UNIQUE INDEX daily_brief_summaries_brief_id_unique ON daily_brief_summaries (brief_id)');
        $this->addSql('CREATE INDEX idx_daily_brief_summaries_generated_at ON daily_brief_summaries (generated_at)');

        $this->addSql('COMMENT ON COLUMN daily_brief_summaries.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN daily_brief_summaries.brief_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN daily_brief_summaries.generated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE daily_brief_summaries');
    }
}
