<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Originally: US-002 daily_briefs + brief_stories.
 * Superseded by Version20260728195706 (Sprint 1 Walking Skeleton consolidation).
 * Kept as no-op to preserve migration history order.
 */
final class Version20260728120003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[no-op] Superseded by Version20260728195706';
    }

    public function up(Schema $schema): void
    {
        // Superseded by Version20260728195706 which creates all Sprint 1 tables.
    }

    public function down(Schema $schema): void
    {
        // No-op: tables managed by Version20260728195706.
    }
}
