<?php

declare(strict_types=1);

namespace App\Domain\Health;

/**
 * Value Object — Statut d'un composant d'infrastructure.
 *
 * Immuable par construction (readonly class PHP 8.2+).
 * Aucune dépendance framework — PHP pur (constitution §4, deptrac Domain:[]).
 */
readonly class ComponentStatus
{
    public function __construct(
        public string $name,
        /** 'ok' | 'degraded' */
        public string $status,
        public string $message,
    ) {
    }

    public function isHealthy(): bool
    {
        return 'ok' === $this->status;
    }
}
