<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * US-034a : crée la table subscriptions pour les abonnements Premium Stripe.
 *
 * Contraintes clés :
 *  - stripe_event_id UNIQUE → clé d'idempotence webhook (INSERT ... ON CONFLICT DO NOTHING)
 *  - stripe_customer_id UNIQUE, stripe_subscription_id UNIQUE → un seul abonnement actif par client
 *  - FK user_id → users.id ON DELETE CASCADE
 *  - Index composé (status, current_period_end) → optimise isPremium()
 */
final class Version20260810100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'US-034a : crée la table subscriptions (Stripe Billing Premium).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE subscriptions (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            stripe_customer_id VARCHAR(255) NOT NULL,
            stripe_subscription_id VARCHAR(255) NOT NULL,
            plan VARCHAR(10) NOT NULL,
            status VARCHAR(20) NOT NULL,
            current_period_end TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            stripe_event_id VARCHAR(255) NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT NOW(),
            PRIMARY KEY (id)
        )");

        $this->addSql('ALTER TABLE subscriptions
            ADD CONSTRAINT fk_subscriptions_user_id
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->addSql('CREATE UNIQUE INDEX subscriptions_stripe_customer_id_unique ON subscriptions (stripe_customer_id)');
        $this->addSql('CREATE UNIQUE INDEX subscriptions_stripe_subscription_id_unique ON subscriptions (stripe_subscription_id)');
        $this->addSql('CREATE UNIQUE INDEX subscriptions_stripe_event_id_unique ON subscriptions (stripe_event_id)');
        $this->addSql('CREATE INDEX idx_subscriptions_status_period_end ON subscriptions (status, current_period_end)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE subscriptions');
    }
}
