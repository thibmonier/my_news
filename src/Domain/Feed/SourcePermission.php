<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Constantes de permission pour les opérations sur les Sources RSS/Atom.
 *
 * Utilisées par :
 *  - Presentation (AdminSourceController) : denyAccessUnlessGranted()
 *  - Infrastructure (SourceVoter)         : voteOnAttribute()
 *
 * Placées dans le Domain pour respecter l'architecture hexagonale (Deptrac) :
 * Presentation:[Domain], Infrastructure:[Domain].
 *
 * Deptrac Domain:[] — aucune dépendance externe.
 */
final class SourcePermission
{
    public const CREATE = 'source_create';
    public const EDIT = 'source_edit';
    public const DELETE = 'source_delete';
    public const TOGGLE = 'source_toggle';
    public const BULK = 'source_bulk';

    /** Constructeur privé — classe de constantes uniquement. */
    private function __construct()
    {
    }
}
