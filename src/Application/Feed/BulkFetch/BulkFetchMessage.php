<?php

declare(strict_types=1);

namespace App\Application\Feed\BulkFetch;

/**
 * Message Messenger — Déclenche la mise à jour de toutes les sources actives.
 *
 * Publié par AdminSourceController sur action "TOUT METTRE À JOUR".
 * BulkFetchHandler itère les sources actives et publie un FetchSourceMessage
 * par source (traitement asynchrone, pas de blocage UI).
 *
 * Deptrac Application:[Domain] — aucune dépendance Infrastructure.
 */
final class BulkFetchMessage
{
}
