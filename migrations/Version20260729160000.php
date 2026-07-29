<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * US-011 — T-011-01 : Index sur synthesis_results.level.
 *
 * La colonne `level` a été créée dans US-010 (Version20260728220000)
 * avec DEFAULT 'standard'. US-011 introduit 3 niveaux distincts :
 * 'concise', 'detailed', 'narrative'.
 *
 * L'index facilite les requêtes analytiques par niveau.
 */
final class Version20260729160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-011 — Index sur synthesis_results.level pour requêtes analytiques par niveau';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE INDEX idx_synthesis_results_level ON synthesis_results (level)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DROP INDEX idx_synthesis_results_level',
        );
    }
}
