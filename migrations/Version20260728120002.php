<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration US-030 — Création de la table `users`.
 *
 * - id          : UUID (type uuid Symfony Bridge, généré en PHP, UUID v7)
 * - email       : VARCHAR(255), UNIQUE NOT NULL — identifiant de connexion
 * - password_hash : VARCHAR(255) — hash Argon2id via libsodium (sodium algorithm)
 * - full_name   : VARCHAR(255) — nom complet de l'utilisateur
 * - roles       : JSON — tableau des rôles Symfony (ROLE_USER par défaut)
 * - created_at  : TIMESTAMPTZ — date de création en UTC
 * - consent_at  : TIMESTAMPTZ — horodatage RGPD de l'acceptation des CGU
 *
 * Index : UNIQUE sur email (déjà implicite via la contrainte UNIQUE de la colonne).
 *
 * Convention : jamais modifier une migration existante (doctrine_migrations.yaml).
 * Rollback : down() supprime la table users.
 */
final class Version20260728120002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-030 : Création table users (inscription par email, Argon2id, RGPD consent_at)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id            UUID                     NOT NULL,
                email         VARCHAR(255)             NOT NULL,
                password_hash VARCHAR(255)             NOT NULL,
                full_name     VARCHAR(255)             NOT NULL,
                roles         JSON                     NOT NULL DEFAULT '["ROLE_USER"]',
                created_at    TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                consent_at    TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT users_email_unique UNIQUE (email)
            )
            SQL);

        // Index sur email pour les lookups de connexion (performances provider)
        $this->addSql('CREATE INDEX idx_users_email ON users (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE users');
    }
}
