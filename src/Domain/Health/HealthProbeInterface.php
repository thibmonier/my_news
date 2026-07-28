<?php

declare(strict_types=1);

namespace App\Domain\Health;

/**
 * Port secondaire (driven port) — Sonde d'infrastructure.
 *
 * Chaque adaptateur Infrastructure implémente ce port pour vérifier
 * la connectivité d'un composant (base de données, Redis, etc.).
 *
 * Conforme à l'architecture hexagonale : interface dans le Domain,
 * implémentations dans Infrastructure (DIP — constitution §4).
 */
interface HealthProbeInterface
{
    /**
     * Vérifie la disponibilité du composant et retourne son statut.
     */
    public function check(): ComponentStatus;
}
