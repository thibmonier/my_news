<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * US-022 — Déduplication SimHash : colonnes articles (title_simhash, is_duplicate, duplicate_of).
 *
 * Ajoute 3 colonnes à la table `articles` :
 * - title_simhash  BIGINT NULL           : SimHash 64 bits du titre normalisé
 * - is_duplicate   BOOLEAN NOT NULL DEFAULT FALSE : doublon sémantique détecté
 * - duplicate_of   UUID NULL             : FK self-référentielle → articles.id
 *
 * Contraintes :
 * - FK FK_BFDD31684463220 → articles(id) ON DELETE SET NULL
 * - INDEX IDX_BFDD31684463220 ON articles (duplicate_of) — index automatique sur FK
 *
 * Coexistence : la déduplication SHA-256 (content_hash) reste active et inchangée.
 */
final class Version20260730100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-022 — Colonnes SimHash sur articles : title_simhash (BIGINT), is_duplicate (BOOL), duplicate_of (UUID FK self-ref ON DELETE SET NULL)';
    }

    public function up(Schema $schema): void
    {
        // Colonne SimHash 64 bits du titre (NULL si non calculé)
        $this->addSql('ALTER TABLE articles ADD title_simhash BIGINT DEFAULT NULL');

        // Colonne indicateur doublon (FALSE par défaut — rétrocompatible)
        $this->addSql('ALTER TABLE articles ADD is_duplicate BOOLEAN DEFAULT false NOT NULL');

        // Colonne référence article original (FK self-ref, NULL si non-doublon)
        $this->addSql('ALTER TABLE articles ADD duplicate_of UUID DEFAULT NULL');

        // Contrainte FK self-référentielle avec ON DELETE SET NULL (nommage Doctrine)
        $this->addSql(
            'ALTER TABLE articles ADD CONSTRAINT FK_BFDD31684463220 FOREIGN KEY (duplicate_of) REFERENCES articles (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE',
        );

        // Index B-tree sur duplicate_of (standard Doctrine pour FK ManyToOne)
        $this->addSql('CREATE INDEX IDX_BFDD31684463220 ON articles (duplicate_of)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE articles DROP CONSTRAINT IF EXISTS FK_BFDD31684463220');
        $this->addSql('DROP INDEX IF EXISTS IDX_BFDD31684463220');
        $this->addSql('ALTER TABLE articles DROP COLUMN IF EXISTS duplicate_of');
        $this->addSql('ALTER TABLE articles DROP COLUMN IF EXISTS is_duplicate');
        $this->addSql('ALTER TABLE articles DROP COLUMN IF EXISTS title_simhash');
    }
}
