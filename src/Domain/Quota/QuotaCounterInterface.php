<?php

declare(strict_types=1);

namespace App\Domain\Quota;

/**
 * Port secondaire — Compteur de quota journalier Redis.
 *
 * Interface Domain décrivant les opérations atomiques sur le compteur de quota.
 * Implémentée par RedisQuotaCounter (Infrastructure/Quota/).
 *
 * Clé Redis : quota:synthesis:{user_uuid}:{YYYY-MM-DD-UTC}
 * TTL : EXPIREAT positionné à la prochaine minuit UTC (reset automatique).
 *
 * RGPD : la clé ne contient que l'UUID (non réversible vers l'email).
 *
 * PHP pur — AUCUN import Symfony/Doctrine/Predis.
 * Constitution §4 : ports Domain = interfaces PHP pures.
 */
interface QuotaCounterInterface
{
    /**
     * Retourne le compteur courant pour un utilisateur à une date UTC (YYYY-MM-DD).
     *
     * Retourne 0 si aucune clé n'existe pour cet utilisateur/cette date.
     *
     * @throws QuotaServiceUnavailableException si le store Redis est inaccessible
     */
    public function getCount(string $userUuid, string $dateUtc): int;

    /**
     * Incrémente le compteur et positionne EXPIREAT (minuit UTC) au 1er appel.
     * Retourne la nouvelle valeur du compteur après incrément.
     *
     * L'EXPIREAT n'est repositionné que si la clé est nouvelle (count = 1 après INCR).
     *
     * @param int $expireAtTimestamp timestamp UNIX de la prochaine minuit UTC
     *
     * @throws QuotaServiceUnavailableException si le store Redis est inaccessible
     */
    public function incrementAndExpire(string $userUuid, string $dateUtc, int $expireAtTimestamp): int;
}
