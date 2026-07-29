<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * US-031 — Table oauth_accounts : liaison identités OAuth (Google, GitHub) aux utilisateurs.
 *
 * Structure :
 * - id : UUID v4 (non séquentiel — constitution §6)
 * - user_id : FK → users.id ON DELETE CASCADE
 * - provider : ENUM CHECK('google','github') NOT NULL
 * - provider_id : identifiant unique chez le provider (sub Google, user ID GitHub)
 * - email_provider : email retourné par le provider (peut être noreply pour GitHub privacy)
 * - created_at : horodatage RGPD du premier consentement OAuth
 *
 * Contraintes :
 * - UNIQUE (provider, provider_id) : 0 doublon par provider
 * - INDEX sur provider, email_provider : requêtes de lookup optimisées
 * - FK avec CASCADE DELETE : cohérence si l'utilisateur est supprimé
 *
 * Les access_tokens provider ne sont JAMAIS persistés ici (exigence RGPD).
 */
final class Version20260729230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-031 — Création table oauth_accounts (FK users.id CASCADE DELETE, UNIQUE provider+provider_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                CREATE TABLE oauth_accounts (
                    id             UUID         NOT NULL,
                    user_id        UUID         NOT NULL,
                    provider       VARCHAR(32)  NOT NULL
                                       CONSTRAINT chk_oauth_accounts_provider
                                           CHECK (provider IN ('google', 'github')),
                    provider_id    VARCHAR(255) NOT NULL,
                    email_provider VARCHAR(255) NOT NULL,
                    created_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    PRIMARY KEY (id),
                    CONSTRAINT fk_oauth_accounts_user_id
                        FOREIGN KEY (user_id)
                        REFERENCES users (id)
                        ON DELETE CASCADE
                )
                SQL,
        );

        $this->addSql(
            'CREATE UNIQUE INDEX uniq_oauth_provider_id ON oauth_accounts (provider, provider_id)',
        );

        $this->addSql(
            'CREATE INDEX idx_oauth_provider ON oauth_accounts (provider)',
        );

        $this->addSql(
            'CREATE INDEX idx_oauth_email_provider ON oauth_accounts (email_provider)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_oauth_provider_id');
        $this->addSql('DROP INDEX IF EXISTS idx_oauth_provider');
        $this->addSql('DROP INDEX IF EXISTS idx_oauth_email_provider');
        $this->addSql('DROP TABLE oauth_accounts');
    }
}
