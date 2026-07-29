<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * US-021 — Enrichissement table sources.
 *
 * Ajouts :
 * - Colonne fetch_interval_minutes INT NOT NULL DEFAULT 30
 * - Colonne deleted_at TIMESTAMPTZ NULL (soft delete)
 * - Contrainte UNIQUE sur url (si absente)
 * - Index sur status et deleted_at
 * - Extension du statut à 25 chars (pending_validation = 18 chars)
 *
 * Les sources existantes conservent leur statut courant (active/inactive).
 * fetch_interval_minutes = 30 pour les sources existantes.
 */
final class Version20260729220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-021 — Sources : fetch_interval_minutes, deleted_at (soft delete), UNIQUE url, statuts enrichis';
    }

    public function up(Schema $schema): void
    {
        // Agrandir la colonne status pour accueillir 'pending_validation' (18 chars)
        $this->addSql('ALTER TABLE sources ALTER COLUMN status TYPE VARCHAR(25)');

        // Ajout fetch_interval_minutes
        $this->addSql('ALTER TABLE sources ADD COLUMN fetch_interval_minutes INT NOT NULL DEFAULT 30');

        // Ajout deleted_at (soft delete)
        $this->addSql('ALTER TABLE sources ADD COLUMN deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN sources.deleted_at IS '(DC2Type:datetime_immutable)'");

        // Contrainte UNIQUE sur url (idempotente via IF NOT EXISTS)
        $this->addSql(
            <<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_constraint
                        WHERE conname = 'sources_url_unique' AND contype = 'u'
                    ) THEN
                        ALTER TABLE sources ADD CONSTRAINT sources_url_unique UNIQUE (url);
                    END IF;
                END $$
                SQL,
        );

        // Index sur status pour les requêtes de filtrage
        $this->addSql(
            <<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_indexes WHERE indexname = 'idx_sources_status'
                    ) THEN
                        CREATE INDEX idx_sources_status ON sources (status);
                    END IF;
                END $$
                SQL,
        );

        // Index sur deleted_at pour le filtre soft-delete
        $this->addSql('CREATE INDEX idx_sources_deleted_at ON sources (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_sources_deleted_at');
        $this->addSql('DROP INDEX IF EXISTS idx_sources_status');
        $this->addSql('ALTER TABLE sources DROP CONSTRAINT IF EXISTS sources_url_unique');
        $this->addSql('ALTER TABLE sources DROP COLUMN IF EXISTS deleted_at');
        $this->addSql('ALTER TABLE sources DROP COLUMN IF EXISTS fetch_interval_minutes');
        $this->addSql('ALTER TABLE sources ALTER COLUMN status TYPE VARCHAR(20)');
    }
}
