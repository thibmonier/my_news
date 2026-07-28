<?php

declare(strict_types=1);

namespace App\Application\Health;

/**
 * Query CQRS — Demande de rapport de santé.
 *
 * DTO sans état : marqueur sémantique du pattern CQRS léger.
 * Aucune dépendance framework — Application dépend uniquement du Domain
 * (deptrac : Application:[Domain]).
 */
final class GetHealthQuery
{
    // Marqueur sans propriétés — la query porte uniquement son intention.
}
