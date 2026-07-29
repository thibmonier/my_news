<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * US-005/T-005-01 — Ajout colonne articles.category.
 *
 * VARCHAR(50) NOT NULL DEFAULT 'productivity' avec contrainte CHECK
 * limitant aux 5 valeurs de l'enum ArticleCategory.
 *
 * Les articles existants (sprint 1) reçoivent le défaut 'productivity'.
 * Index idx_articles_category pour les requêtes analytiques futures.
 */
final class Version20260729210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-005 — Ajout colonne articles.category (enum éditorial : ai_insight|geopolitics|productivity|research|sustainability)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                ALTER TABLE articles
                    ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'productivity'
                        CONSTRAINT chk_articles_category CHECK (
                            category IN (
                                'ai_insight',
                                'geopolitics',
                                'productivity',
                                'research',
                                'sustainability'
                            )
                        )
                SQL,
        );

        $this->addSql(
            'CREATE INDEX idx_articles_category ON articles (category)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_articles_category');
        $this->addSql('ALTER TABLE articles DROP COLUMN category');
    }
}
