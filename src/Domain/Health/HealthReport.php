<?php

declare(strict_types=1);

namespace App\Domain\Health;

/**
 * Value Object — Rapport de santé de la stack.
 *
 * Immuable (readonly class). Agrège les ComponentStatus issus de chaque sonde.
 * Aucune dépendance framework — PHP pur (constitution §4, deptrac Domain:[]).
 *
 * Règle d'agrégation : status='ok' ssi TOUS les composants sont 'ok'.
 */
readonly class HealthReport
{
    /** @var list<ComponentStatus> */
    private array $components;

    /**
     * @param list<ComponentStatus> $components
     */
    public function __construct(array $components)
    {
        $this->components = $components;
    }

    /**
     * @return list<ComponentStatus>
     */
    public function getComponents(): array
    {
        return $this->components;
    }

    /**
     * Retourne 'ok' si tous les composants sont sains, 'degraded' sinon.
     */
    public function getStatus(): string
    {
        return $this->isHealthy() ? 'ok' : 'degraded';
    }

    /**
     * true si et seulement si tous les composants sont 'ok'.
     */
    public function isHealthy(): bool
    {
        foreach ($this->components as $component) {
            if (!$component->isHealthy()) {
                return false;
            }
        }

        return true;
    }
}
