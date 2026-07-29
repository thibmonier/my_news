<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Originally: Seed des 3 sources RSS Walking Skeleton.
 * Superseded by Version20260728195708 which runs after tables are created.
 * Kept as no-op to preserve migration history order.
 */
final class Version20260728120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[no-op] Superseded by Version20260728195708';
    }

    public function up(Schema $schema): void
    {
        // Seed data moved to Version20260728195708 (runs after table creation in 195706).
    }

    public function down(Schema $schema): void
    {
        // No-op.
    }
}
