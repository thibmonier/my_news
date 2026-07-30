<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * US-032 — Gestion du profil utilisateur.
 *
 * Ajoute les colonnes de profil sur la table `users` :
 *   - bio                    : VARCHAR(280) NULLABLE  — bio professionnelle (max 280 caractères)
 *   - email_pending          : VARCHAR(255) NULLABLE  — email en attente de validation (double opt-in)
 *   - email_pending_token    : VARCHAR(36)  NULLABLE  — token UUID v4 de confirmation (TTL 24h)
 *   - email_pending_expires_at : TIMESTAMPTZ NULLABLE — date d'expiration du token
 */
final class Version20260730194509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-032 : ajoute bio, email_pending, email_pending_token, email_pending_expires_at sur la table users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD bio VARCHAR(280) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD email_pending VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD email_pending_token VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD email_pending_expires_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP bio');
        $this->addSql('ALTER TABLE users DROP email_pending');
        $this->addSql('ALTER TABLE users DROP email_pending_token');
        $this->addSql('ALTER TABLE users DROP email_pending_expires_at');
    }
}
