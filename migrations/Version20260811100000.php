<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-034b : ajoute cancel_at_period_end à subscriptions (Customer Portal + cycle de vie).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions ADD COLUMN cancel_at_period_end BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions DROP COLUMN cancel_at_period_end');
    }
}
