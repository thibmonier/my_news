<?php

declare(strict_types=1);

namespace App\Application\Quota;

use App\Domain\Quota\QuotaCounterInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;
use App\Domain\Subscription\SubscriptionRepositoryInterface;

/**
 * Service applicatif — Gestion du quota quotidien de synthèses IA.
 *
 * Implémente la logique métier US-013 (remplace US-033) :
 * - Limite : 3 synthèses/jour/compte (plan free)
 * - Bypass Premium : un abonnement actif (SubscriptionRepositoryInterface::isPremium) lève
 *   la limite — aucun appel Redis pour les comptes Premium.
 * - Réinitialisation automatique à minuit UTC via EXPIREAT Redis
 * - Remboursement quota sur erreur serveur IA : QuotaService::refund()
 * - Clé Redis : quota:synthesis:{uuid}:{YYYY-MM-DD-UTC}
 * - Fail-safe : QuotaServiceUnavailableException si Redis KO → HTTP 503 sans bypass
 *
 * RGPD : clé Redis = UUID uniquement (non réversible vers email ou IP).
 * Sécurité :
 *   - compteur lié à user.uuid — impossible à contourner par VPN
 *   - date UTC serveur uniquement — header X-Date client ignoré et loggué en WARNING
 *
 * Dépend uniquement d'interfaces Domain (deptrac : Application → Domain).
 *
 * @see QuotaCounterInterface
 * @see SubscriptionRepositoryInterface
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
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
    ) {
    }

    /**
     * Vérifie si l'utilisateur a un abonnement Premium actif.
     *
     * PHPDoc RGPD : seul l'UUID est transmis — jamais l'email ni une donnée PII.
     * Délègue à SubscriptionRepositoryInterface (Domain) — PHP pur, sans Doctrine direct.
     *
     * @throws QuotaServiceUnavailableException jamais — la vérification Premium est DB,
     *                                          pas Redis ; les erreurs DB propagent une exception PHP standard
     */
    public function isPremium(string $userUuid): bool
    {
        return $this->subscriptionRepository->isPremium($userUuid);
    }

    /**
     * Tente de consommer 1 unité du quota quotidien (UTC).
     *
     * Algorithme :
     * 0. Si isPremium(uuid) → retourne true sans appel Redis (bypass total)
     * 1. Lit le compteur courant (GET Redis — atomique)
     * 2. Si compteur >= 3 : retourne false sans incrément (quota épuisé)
     * 3. Sinon : INCR + EXPIREAT à minuit UTC si 1er usage → retourne true
     *
     * Note : race condition possible entre GET et INCR pour les comptes Free
     * (acceptable Sprint 4 — remplacer par Lua atomique si nécessaire).
     *
     * @throws QuotaServiceUnavailableException si Redis est inaccessible (fail-safe)
     */
    public function consumeOrDeny(string $userUuid): bool
    {
        // Bypass Premium : aucun appel Redis (US-013 T-013-02)
        if ($this->isPremium($userUuid)) {
            return true;
        }

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
     * Rembourse 1 unité de quota après une erreur serveur IA (Mistral 5xx).
     *
     * À appeler UNIQUEMENT si le quota a été pré-débité (compte Free) et que
     * l'erreur est côté serveur IA — PAS sur InvalidSynthesisUrlException (erreur client).
     *
     * Délègue à QuotaCounterInterface::decrement() qui garantit plancher à 0.
     *
     * @throws QuotaServiceUnavailableException si Redis est inaccessible
     */
    public function refund(string $userUuid): void
    {
        $this->counter->decrement($userUuid, $this->todayUtc());
    }

    /**
     * Retourne le nombre de synthèses restantes pour aujourd'hui (UTC).
     *
     * Bypass Premium : retourne DAILY_LIMIT sans appel Redis.
     *
     * @throws QuotaServiceUnavailableException si Redis est inaccessible
     */
    public function getRemaining(string $userUuid): int
    {
        if ($this->isPremium($userUuid)) {
            return self::DAILY_LIMIT;
        }

        $count = $this->counter->getCount($userUuid, $this->todayUtc());

        return max(0, self::DAILY_LIMIT - $count);
    }

    /**
     * Retourne le nombre de synthèses consommées aujourd'hui (UTC).
     * Plafonné à DAILY_LIMIT pour éviter d'exposer des compteurs > 3.
     *
     * Bypass Premium : retourne 0 sans appel Redis.
     *
     * @throws QuotaServiceUnavailableException si Redis est inaccessible
     */
    public function getUsed(string $userUuid): int
    {
        if ($this->isPremium($userUuid)) {
            return 0;
        }

        return min(self::DAILY_LIMIT, $this->counter->getCount($userUuid, $this->todayUtc()));
    }

    /**
     * Retourne la date/heure ISO8601 UTC de la prochaine minuit (reset quota).
     *
     * Format : 2026-08-12T00:00:00+00:00 (DateTimeInterface::ATOM, UTC)
     * Utilisé dans le corps JSON de la réponse HTTP 402 (resets_at).
     *
     * Sécurité : calculé côté serveur — le header X-Date client est IGNORÉ.
     */
    public function nextMidnightUtcIso(): string
    {
        return (new \DateTimeImmutable('tomorrow 00:00:00', new \DateTimeZone('UTC')))
            ->format(\DateTimeInterface::ATOM);
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
     */
    private function nextMidnightUtcTimestamp(): int
    {
        return (new \DateTimeImmutable('tomorrow 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
    }
}
