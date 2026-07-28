<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration US-010 — Table `synthesis_results`.
 *
 * Crée la table de persistance des résultats de synthèse IA Mistral.
 *
 * Schema :
 * - id           : UUID v4, PK
 * - url_hash     : SHA-256 hexadécimal de l'URL source (64 chars) — analytics, RGPD-safe
 * - level        : niveau de synthèse ('standard' Sprint 1)
 * - content      : condensé IA incluant "BRIEFLY AI:" (~200 mots)
 * - key_points   : JSONB array — 3 points clés numérotés 01/02/03
 * - sources      : JSONB array — sources citées
 * - created_at   : horodatage UTC de création — analytics Sprint 1
 *
 * RGPD : aucune FK utilisateur (conformité story US-010 §Conversation).
 * Traçabilité : persistence systématique pour analytics (pas de déduplication Sprint 1).
 *
 * Index :
 * - url_hash   : recherche rapide par URL (analytics + cache US-012 backlog)
 * - created_at : tri chronologique (analytics)
 */
final class Version20260728220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-010 — Table synthesis_results : persistance des synthèses IA Mistral';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE synthesis_results (
                id         UUID         NOT NULL,
                url_hash   VARCHAR(64)  NOT NULL,
                level      VARCHAR(16)  NOT NULL DEFAULT 'standard',
                content    TEXT         NOT NULL,
                key_points JSON         NOT NULL DEFAULT '[]',
                sources    JSON         NOT NULL DEFAULT '[]',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('COMMENT ON COLUMN synthesis_results.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN synthesis_results.created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql(
            'CREATE INDEX idx_synthesis_results_url_hash ON synthesis_results (url_hash)',
        );
        $this->addSql(
            'CREATE INDEX idx_synthesis_results_created_at ON synthesis_results (created_at)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE synthesis_results');
    }
}
