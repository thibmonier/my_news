<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

/**
 * Value Object Domain — Réglages de confidentialité RGPD d'un utilisateur (US-035).
 *
 * Immutable : toute modification retourne une nouvelle instance (`withUpdatedAt`).
 * Valeurs par défaut opt-in (true) — conforme RGPD pour le fonctionnement de base.
 *
 * Champs :
 *   - analyticsOptIn          : collecte analytique anonyme (Plausible/Matomo)
 *   - personalizedRecs        : recommandations personnalisées par historique
 *   - searchEngineIndexing    : indexation profil par les moteurs de recherche
 *   - updatedAt               : horodatage UTC de la dernière modification (traçabilité RGPD)
 *
 * PHP pur — AUCUN import Symfony/Doctrine (constitution §4).
 */
final readonly class UserPrivacySettings
{
    public function __construct(
        public bool $analyticsOptIn,
        public bool $personalizedRecs,
        public bool $searchEngineIndexing,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Retourne les valeurs par défaut opt-in (utilisées si aucune entrée en base).
     * Conformité RGPD : opt-in implicite pour le fonctionnement de base.
     */
    public static function defaults(): self
    {
        return new self(
            analyticsOptIn: true,
            personalizedRecs: true,
            searchEngineIndexing: true,
            updatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /**
     * Retourne une nouvelle instance avec l'horodatage mis à jour (immutabilité).
     */
    public function withUpdatedAt(\DateTimeImmutable $at): self
    {
        return new self(
            analyticsOptIn: $this->analyticsOptIn,
            personalizedRecs: $this->personalizedRecs,
            searchEngineIndexing: $this->searchEngineIndexing,
            updatedAt: $at,
        );
    }
}
