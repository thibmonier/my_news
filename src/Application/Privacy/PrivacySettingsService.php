<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Domain\Privacy\PrivacySettingsRepositoryInterface;
use App\Domain\Privacy\UserPrivacySettings;

/**
 * Service Application — Réglages de confidentialité RGPD (US-035 T-035-05).
 *
 * getSettings()    : lit les préférences depuis le repository (valeurs par défaut si absent).
 * updateSettings() : construit un nouveau VO avec updatedAt=now UTC, persiste via repository.
 * exportAsJson()   : retourne les préférences au format portabilité RGPD Article 20.
 *
 * Couche Application — dépend de Domain uniquement (deptrac conforme).
 */
final class PrivacySettingsService
{
    public function __construct(
        private readonly PrivacySettingsRepositoryInterface $repository,
    ) {
    }

    /**
     * Retourne les réglages de confidentialité d'un utilisateur.
     * Si aucune entrée n'existe, retourne les valeurs par défaut opt-in.
     *
     * @param string $userId UUID RFC 4122
     */
    public function getSettings(string $userId): UserPrivacySettings
    {
        return $this->repository->findByUserId($userId);
    }

    /**
     * Met à jour les réglages de confidentialité d'un utilisateur.
     *
     * L'horodatage `updatedAt` est automatiquement fixé à l'instant courant en UTC
     * pour assurer la traçabilité RGPD.
     *
     * @param string $userId UUID RFC 4122
     */
    public function updateSettings(
        string $userId,
        bool $analyticsOptIn,
        bool $personalizedRecs,
        bool $searchEngineIndexing,
    ): void {
        $settings = new UserPrivacySettings(
            analyticsOptIn: $analyticsOptIn,
            personalizedRecs: $personalizedRecs,
            searchEngineIndexing: $searchEngineIndexing,
            updatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $this->repository->save($userId, $settings);
    }

    /**
     * Exporte les réglages au format JSON portabilité RGPD (Article 20).
     *
     * Format : { analytics_opt_in, personalized_recs, search_engine_indexing, updated_at (ISO8601) }
     *
     * @param string $userId UUID RFC 4122
     *
     * @return array{analytics_opt_in: bool, personalized_recs: bool, search_engine_indexing: bool, updated_at: string}
     */
    public function exportAsJson(string $userId): array
    {
        $settings = $this->repository->findByUserId($userId);

        return [
            'analytics_opt_in' => $settings->analyticsOptIn,
            'personalized_recs' => $settings->personalizedRecs,
            'search_engine_indexing' => $settings->searchEngineIndexing,
            'updated_at' => $settings->updatedAt->format(\DateTimeInterface::RFC3339),
        ];
    }
}
