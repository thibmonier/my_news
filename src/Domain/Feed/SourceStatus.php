<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Statut d'une source RSS/Atom.
 *
 * PHP pur — aucune dépendance framework (constitution §4, deptrac Domain:[]).
 */
enum SourceStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function isActive(): bool
    {
        return self::Active === $this;
    }
}
