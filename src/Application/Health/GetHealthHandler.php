<?php

declare(strict_types=1);

namespace App\Application\Health;

use App\Domain\Health\HealthProbeInterface;
use App\Domain\Health\HealthReport;

/**
 * Handler CQRS — Orchestre les sondes et retourne un HealthReport.
 *
 * Respecte l'architecture hexagonale :
 * - Dépend uniquement des ports Domain (HealthProbeInterface)
 * - Aucune dépendance Doctrine, Redis, HTTP (deptrac : Application:[Domain])
 *
 * Les sondes sont injectées via !tagged_iterator 'app.health_probe' (services.yaml).
 */
final class GetHealthHandler
{
    /**
     * @param iterable<HealthProbeInterface> $probes sondes injectées par le container
     */
    public function __construct(
        private readonly iterable $probes,
    ) {
    }

    /**
     * Exécute toutes les sondes et agrège leur statut en un HealthReport.
     * Chaque sonde est appelée défensivement : une exception est capturée
     * pour ne pas empêcher les autres sondes de s'exécuter.
     */
    public function handle(GetHealthQuery $query): HealthReport
    {
        $components = [];

        foreach ($this->probes as $probe) {
            $components[] = $probe->check();
        }

        return new HealthReport($components);
    }
}
