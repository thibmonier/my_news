<?php

declare(strict_types=1);

namespace App\Application\Feed\ValidateSource;

/**
 * Message Messenger — Déclenche la validation asynchrone d'une source.
 *
 * Après création admin, la source est en status=pending_validation.
 * Ce message est consommé par ValidateSourceHandler qui effectue
 * un HEAD HTTP vers l'URL pour vérifier le Content-Type RSS/Atom.
 *
 * Deptrac Application:[Domain] — aucune dépendance Infrastructure.
 */
final class ValidateSourceMessage
{
    public function __construct(
        /**
         * UUID v4 de la Source à valider.
         *
         * @var non-empty-string
         */
        public readonly string $sourceId,
    ) {
    }
}
