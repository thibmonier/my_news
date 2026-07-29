<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Statut d'une source RSS/Atom.
 *
 * PHP pur — aucune dépendance framework (constitution §4, deptrac Domain:[]).
 *
 * Cycle de vie :
 *   active → (admin toggle) → inactive
 *   (admin add) → pending_validation → (ValidateSourceHandler) → active | validation_failed
 *   active | inactive | validation_failed → (admin delete) → deleted
 */
enum SourceStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case PendingValidation = 'pending_validation';
    case ValidationFailed = 'validation_failed';
    case Deleted = 'deleted';

    public function isActive(): bool
    {
        return self::Active === $this;
    }

    /**
     * Libellé lisible pour l'interface admin.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Inactive => 'Inactif',
            self::PendingValidation => 'En attente de validation',
            self::ValidationFailed => 'Échec de validation',
            self::Deleted => 'Supprimé',
        };
    }

    /**
     * Classe CSS Badge Tailwind pour l'interface admin.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'badge-success',
            self::Inactive => 'badge-neutral',
            self::PendingValidation => 'badge-warning',
            self::ValidationFailed => 'badge-error',
            self::Deleted => 'badge-ghost',
        };
    }
}
