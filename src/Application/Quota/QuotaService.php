<?php

declare(strict_types=1);

namespace App\Application\Quota;

use App\Domain\Quota\QuotaCounterInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;

/**
 * Service applicatif — Gestion du quota quotidien de synthèses IA.
 *
 * Implémente la logique métier US-033 :
 * - Limite : 3 synthèses/jour/compte (plan free)
 * - Réinitialisation automatique à minuit UTC via EXPIREAT Redis
 * - Clé Redis : quota:synthesis:{uuid}:{YYYY-MM-DD-UTC}
 * - Fail-safe : QuotaServiceUnavailableException si Redis KO → HTTP 503 sans bypass
 *
 * RGPD : clé Redis = UUID uniquement (non réversible vers email ou IP).
 * Sécurité : compteur lié à user.uuid — impossible à contourner par VPN.
 *
 * Dépend uniquement d'interfaces Domain (deptrac : Application → Domain).
 *
 * @see QuotaCounterInterface
 * @see QuotaServiceUnavailableException
 */
final class QuotaService
{
    /**
     * Limite quotidienne de synthèses pour un compte free.
     * Sprint 1 : 3 — sera configurable via paramètre Symfony en US-034.
     */
    public const DAILY_LIMIT = 3;

    public function __construct(
        private readonly QuotaCounterInterface $counter,
    ) {
    }

    /**
     * Tente de consommer 1 unité du quota quotidien (UTC).
     *
     * Algorithme :
     * 1. Lit le compteur courant (GET Redis — atomique)
     * 2. Si compteur >= 3 : retourne false sans incrément (quota épuisé)
     * 3. Sinon : INCR + EXPIREAT à minuit UTC si 1er usage → retourne true
     *
     * Note : race condition possible entre GET et INCR (acceptable Sprint 1,
     * remplacer par un script Lua atomique en production si nécessaire).
     *
     * @throws QuotaServiceUnavailableException si Redis est inaccessible (fail-safe)
     */
    public function consumeOrDeny(string $userUuid): bool
    {
        $dateUtc = $this->todayUtc();
        $expireAt = $this->nextMidnightUtcTimestamp();

        $current = $this->counter->getCount($userUuid, $dateUtc);

        if ($current >= self::DAILY_LIMIT) {
            return false;
        }

        $this->counter->incrementAndExpire($userUuid, $dateUtc, $expireAt);

        return true;
    }

    /**
     * Retourne le nombre de synthèses restantes pour aujourd'hui (UTC).
     *
     * @throws QuotaServiceUnavailableException si Redis est inaccessible
     */
    public function getRemaining(string $userUuid): int
    {
        $count = $this->counter->getCount($userUuid, $this->todayUtc());

        return max(0, self::DAILY_LIMIT - $count);
    }

    /**
     * Retourne le nombre de synthèses consommées aujourd'hui (UTC).
     * Plafonné à DAILY_LIMIT pour éviter d'exposer des compteurs > 3.
     *
     * @throws QuotaServiceUnavailableException si Redis est inaccessible
     */
    public function getUsed(string $userUuid): int
    {
        return min(self::DAILY_LIMIT, $this->counter->getCount($userUuid, $this->todayUtc()));
    }

    // ── Helpers internes ───────────────────────────────────────────────────────

    /**
     * Retourne la date UTC d'aujourd'hui au format YYYY-MM-DD.
     */
    private function todayUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
    }

    /**
     * Calcule le timestamp UNIX de la prochaine minuit UTC.
     *
     * Utilisé pour EXPIREAT Redis (TTL dynamique, reset à minuit UTC).
     * Exemple : si maintenant = 2026-07-28 23:58 UTC, retourne le timestamp de 2026-07-29 00:00:00 UTC.
     */
    private function nextMidnightUtcTimestamp(): int
    {
        return (new \DateTimeImmutable('tomorrow 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
    }
}
