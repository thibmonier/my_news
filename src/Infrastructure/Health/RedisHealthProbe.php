<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

use App\Domain\Health\ComponentStatus;
use App\Domain\Health\HealthProbeInterface;
use Predis\Client;

/**
 * Adapter — Sonde de connectivité Redis.
 *
 * Implémente le port Domain\Health\HealthProbeInterface dans la couche
 * Infrastructure (deptrac : Infrastructure:[Domain, Application]).
 *
 * Utilise predis/predis (ADR-006) : PING → PONG.
 */
final class RedisHealthProbe implements HealthProbeInterface
{
    public function __construct(
        private readonly Client $redisClient,
    ) {
    }

    public function check(): ComponentStatus
    {
        try {
            $this->redisClient->ping();

            return new ComponentStatus(
                name: 'redis',
                status: 'ok',
                message: 'Redis connected',
            );
        } catch (\Throwable $e) {
            return new ComponentStatus(
                name: 'redis',
                status: 'degraded',
                message: 'Redis unreachable: ' . $e->getMessage(),
            );
        }
    }
}
