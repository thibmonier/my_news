<?php

declare(strict_types=1);

namespace App\Application\Brief\GenerateDailyBrief;

/**
 * Message Messenger — Déclenche la génération du Daily Brief pour une date cible.
 *
 * SÉCURITÉ OWASP : aucune donnée personnelle dans ce DTO.
 * La date est au format ISO (YYYY-MM-DD), pas d'UUID utilisateur.
 *
 * Deptrac Application:[Domain] — aucune dépendance Infrastructure.
 */
final class GenerateDailyBriefMessage
{
    public function __construct(
        /**
         * Date cible au format Y-m-d (ex: "2026-07-28").
         * String pour sérialisation Symfony Messenger (JSON transport).
         */
        public readonly string $dateTarget,
    ) {
        // Validation basique du format pour éviter une injection de date malformée
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->dateTarget)) {
            throw new \InvalidArgumentException(\sprintf('GenerateDailyBriefMessage dateTarget must be Y-m-d format, got "%s".', $this->dateTarget));
        }
    }

    /** Retourne la date cible sous forme d'objet immuable (UTC). */
    public function getDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->dateTarget, new \DateTimeZone('UTC'));
    }
}
