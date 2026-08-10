<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-035 : crée la table user_privacy_settings (réglages de confidentialité RGPD).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE user_privacy_settings (
            user_id UUID NOT NULL,
            analytics_opt_in BOOLEAN NOT NULL DEFAULT TRUE,
            personalized_recs BOOLEAN NOT NULL DEFAULT TRUE,
            search_engine_indexing BOOLEAN NOT NULL DEFAULT TRUE,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT NOW(),
            PRIMARY KEY (user_id)
        )");

        $this->addSql('ALTER TABLE user_privacy_settings
            ADD CONSTRAINT fk_user_privacy_settings_user_id
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_privacy_settings');
    }
}
