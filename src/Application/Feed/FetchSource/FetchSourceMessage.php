<?php

declare(strict_types=1);

namespace App\Application\Feed\FetchSource;

/**
 * Message Messenger — Déclenche le fetch d'une source RSS/Atom.
 *
 * Routé vers le transport `async` (Redis Streams) par la config Messenger.
 * Un message par source active est publié par FetchAllSourcesTask.
 *
 * Deptrac Application:[Domain] — aucune dépendance Infrastructure.
 */
final class FetchSourceMessage
{
    public function __construct(
        /**
         * UUID v4 de la Source à ingérer.
         *
         * @var non-empty-string
         */
        public readonly string $sourceId,
    ) {
    }
}
