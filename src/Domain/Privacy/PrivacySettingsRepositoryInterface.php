<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

/**
 * Port secondaire Domain — Persistance des réglages de confidentialité (US-035).
 *
 * Implémenté par DoctrinePrivacySettingsRepository (Infrastructure).
 * Le Domain ne dépend jamais de l'infrastructure (DIP — constitution §4).
 *
 * PHP pur — AUCUN import Symfony/Doctrine.
 */
interface PrivacySettingsRepositoryInterface
{
    /**
     * Retourne les réglages de confidentialité pour un utilisateur donné.
     *
     * Si aucune entrée n'existe en base (nouvel utilisateur, avant Sprint 4),
     * retourne les valeurs par défaut opt-in `UserPrivacySettings::defaults()`.
     *
     * @param string $userId UUID RFC 4122 de l'utilisateur
     */
    public function findByUserId(string $userId): UserPrivacySettings;

    /**
     * Persiste (crée ou met à jour) les réglages de confidentialité.
     *
     * Utilise un upsert (INSERT ON CONFLICT DO UPDATE) pour gérer les deux cas.
     *
     * @param string $userId UUID RFC 4122 de l'utilisateur
     */
    public function save(string $userId, UserPrivacySettings $settings): void;
}
