<?php

declare(strict_types=1);

namespace App\Infrastructure\Quota;

use App\Domain\Quota\QuotaCounterInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;
use Predis\Client;

/**
 * Adapter Redis — Compteur de quota journalier des synthèses IA.
 *
 * Implémente QuotaCounterInterface (port Domain) via Predis.
 *
 * Clé Redis : quota:synthesis:{uuid}:{YYYY-MM-DD}
 * Opérations Redis utilisées :
 * - GET  : lecture atomique du compteur courant
 * - INCR : incrément atomique du compteur
 * - EXPIREAT : TTL absolu à la prochaine minuit UTC
 *
 * RGPD : la clé Redis ne contient que l'UUID (non réversible vers email/IP).
 * Aucune PII (Personally Identifiable Information) stockée dans Redis.
 *
 * Fail-safe : toute exception Predis/Redis est wrappée en
 * QuotaServiceUnavailableException → HTTP 503 sans bypass du quota.
 *
 * Deptrac : Infrastructure → Domain (QuotaCounterInterface, QuotaServiceUnavailableException).
 */
final class RedisQuotaCounter implements QuotaCounterInterface
{
    private const KEY_PREFIX = 'quota:synthesis:';

    public function __construct(
        private readonly Client $redisClient,
    ) {
    }

    /**
     * Lit le compteur courant depuis Redis pour un UUID/date donnés.
     * Retourne 0 si la clé n'existe pas encore (1er accès de la journée).
     *
     * @throws QuotaServiceUnavailableException si Redis est inaccessible
     */
    public function getCount(string $userUuid, string $dateUtc): int
    {
        try {
            $value = $this->redisClient->get($this->buildKey($userUuid, $dateUtc));

            return (int) ($value ?? 0);
        } catch (\Throwable $e) {
            throw new QuotaServiceUnavailableException($e->getMessage(), $e);
        }
    }

    /**
     * Incrémente le compteur et positionne EXPIREAT à la prochaine minuit UTC.
     *
     * L'EXPIREAT est positionné UNIQUEMENT si la clé est nouvelle (count = 1 après INCR).
     * Cela garantit que le TTL n'est pas réinitialisé à chaque appel.
     *
     * @param int $expireAtTimestamp timestamp UNIX de la prochaine minuit UTC
     *
     * @throws QuotaServiceUnavailableException si Redis est inaccessible
     */
    public function incrementAndExpire(string $userUuid, string $dateUtc, int $expireAtTimestamp): int
    {
        try {
            $key = $this->buildKey($userUuid, $dateUtc);
            $newCount = $this->redisClient->incr($key);

            // Positionner EXPIREAT uniquement au 1er incrément (clé créée par INCR)
            // Évite de repousser l'expiry sur chaque appel.
            if (1 === $newCount) {
                $this->redisClient->expireat($key, $expireAtTimestamp);
            }

            return $newCount;
        } catch (\Throwable $e) {
            throw new QuotaServiceUnavailableException($e->getMessage(), $e);
        }
    }

    /**
     * Construit la clé Redis : quota:synthesis:{uuid}:{YYYY-MM-DD}.
     *
     * Format de la clé documenté dans la tech-spec §9.1.
     * Contient uniquement UUID + date — aucune donnée personnelle.
     */
    private function buildKey(string $userUuid, string $dateUtc): string
    {
        return self::KEY_PREFIX . $userUuid . ':' . $dateUtc;
    }
}
