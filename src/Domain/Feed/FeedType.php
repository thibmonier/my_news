<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Enumération des formats de flux pris en charge.
 *
 * Valeur backing string utilisée comme discriminant en base de données.
 * PHP pur — aucune dépendance framework (constitution §4, deptrac Domain:[]).
 */
enum FeedType: string
{
    case Rss = 'rss';
    case Atom = 'atom';
}
