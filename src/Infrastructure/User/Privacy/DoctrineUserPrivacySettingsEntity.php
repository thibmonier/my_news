<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Privacy;

use App\Domain\Privacy\UserPrivacySettings;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entité Doctrine — réglages de confidentialité RGPD par utilisateur (US-035 T-035-01).
 *
 * Table : user_privacy_settings
 * PK    : user_id (UUID — pas d'id auto-increment)
 * FK    : user_id REFERENCES users(id) ON DELETE CASCADE
 *
 * Valeurs par défaut opt-in (DEFAULT TRUE) — conformité RGPD :
 * les utilisateurs antérieurs à ce sprint bénéficient du fonctionnement
 * par défaut jusqu'à ce qu'ils modifient explicitement leurs préférences.
 *
 * Couche Infrastructure — AUCUNE dépendance Domain directe (anti-corruption via toDomain/fromDomain).
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_privacy_settings')]
class DoctrineUserPrivacySettingsEntity
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: 'guid')]
    private string $userId;

    #[ORM\Column(name: 'analytics_opt_in', type: 'boolean', options: ['default' => true])]
    private bool $analyticsOptIn;

    #[ORM\Column(name: 'personalized_recs', type: 'boolean', options: ['default' => true])]
    private bool $personalizedRecs;

    #[ORM\Column(name: 'search_engine_indexing', type: 'boolean', options: ['default' => true])]
    private bool $searchEngineIndexing;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $userId,
        bool $analyticsOptIn,
        bool $personalizedRecs,
        bool $searchEngineIndexing,
        \DateTimeImmutable $updatedAt,
    ) {
        $this->userId = $userId;
        $this->analyticsOptIn = $analyticsOptIn;
        $this->personalizedRecs = $personalizedRecs;
        $this->searchEngineIndexing = $searchEngineIndexing;
        $this->updatedAt = $updatedAt;
    }

    public static function fromDomain(string $userId, UserPrivacySettings $settings): self
    {
        return new self(
            userId: $userId,
            analyticsOptIn: $settings->analyticsOptIn,
            personalizedRecs: $settings->personalizedRecs,
            searchEngineIndexing: $settings->searchEngineIndexing,
            updatedAt: $settings->updatedAt,
        );
    }

    public function toDomain(): UserPrivacySettings
    {
        return new UserPrivacySettings(
            analyticsOptIn: $this->analyticsOptIn,
            personalizedRecs: $this->personalizedRecs,
            searchEngineIndexing: $this->searchEngineIndexing,
            updatedAt: $this->updatedAt,
        );
    }

    public function updateFrom(UserPrivacySettings $settings): void
    {
        $this->analyticsOptIn = $settings->analyticsOptIn;
        $this->personalizedRecs = $settings->personalizedRecs;
        $this->searchEngineIndexing = $settings->searchEngineIndexing;
        $this->updatedAt = $settings->updatedAt;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }
}
