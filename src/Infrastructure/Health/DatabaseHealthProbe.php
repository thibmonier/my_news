<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

use App\Domain\Health\ComponentStatus;
use App\Domain\Health\HealthProbeInterface;
use Doctrine\DBAL\Connection;

/**
 * Adapter — Sonde de connectivité PostgreSQL.
 *
 * Implémente le port Domain\Health\HealthProbeInterface dans la couche
 * Infrastructure (deptrac : Infrastructure:[Domain, Application]).
 *
 * Exécute SELECT 1 via DBAL (connexion lazy — ne crée pas de connexion au boot).
 */
final class DatabaseHealthProbe implements HealthProbeInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function check(): ComponentStatus
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return new ComponentStatus(
                name: 'database',
                status: 'ok',
                message: 'PostgreSQL connected',
            );
        } catch (\Throwable $e) {
            return new ComponentStatus(
                name: 'database',
                status: 'degraded',
                message: 'PostgreSQL unreachable: ' . $e->getMessage(),
            );
        }
    }
}
