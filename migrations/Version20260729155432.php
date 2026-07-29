<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration US-004 — Table `article_summaries`.
 *
 * Crée la table de persistance des condensés IA par article.
 *
 * Schéma :
 * - id               : UUID v4, PK
 * - article_id       : UUID de l'article source (index — pas de FK pour découplage)
 * - key_points       : JSON array de puces (3-4 éléments ≤ 120 chars chacune)
 * - model_version    : version du modèle IA (traçabilité RGPD — 'mistral-small-latest', 'gpt-4o-mini', '')
 * - is_degraded      : true si tous les fournisseurs IA étaient KO
 * - degraded_content : extrait RSS brut ≤ 280 chars (null si non dégradé)
 * - cached_at        : horodatage UTC de génération
 * - expires_at       : cached_at + 24h (TTL logique)
 *
 * RGPD : aucune FK utilisateur — article_id uniquement (UUID non-séquentiel).
 *
 * Index :
 * - article_id : recherche rapide par article (cache aside Redis → DB fallback)
 * - expires_at : purge des condensés expirés (batch ou cron futur)
 */
final class Version20260729155432 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-004 — Table article_summaries : persistance des condensés IA par article';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE article_summaries (
                id                UUID         NOT NULL,
                article_id        UUID         NOT NULL,
                key_points        JSON         NOT NULL,
                model_version     VARCHAR(64)  NOT NULL,
                is_degraded       BOOLEAN      NOT NULL,
                degraded_content  TEXT         DEFAULT NULL,
                cached_at         TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at        TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql(
            'COMMENT ON COLUMN article_summaries.id IS \'(DC2Type:uuid)\'',
        );
        $this->addSql(
            'COMMENT ON COLUMN article_summaries.article_id IS \'(DC2Type:uuid)\'',
        );
        $this->addSql(
            'COMMENT ON COLUMN article_summaries.cached_at IS \'(DC2Type:datetime_immutable)\'',
        );
        $this->addSql(
            'COMMENT ON COLUMN article_summaries.expires_at IS \'(DC2Type:datetime_immutable)\'',
        );

        $this->addSql(
            'CREATE INDEX idx_article_summaries_article_id ON article_summaries (article_id)',
        );
        $this->addSql(
            'CREATE INDEX idx_article_summaries_expires_at ON article_summaries (expires_at)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE article_summaries');
    }
}
