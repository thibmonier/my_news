<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Privacy;

use App\Domain\Privacy\PrivacySettingsRepositoryInterface;
use App\Domain\Privacy\UserPrivacySettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Adapter Infrastructure — persistance des réglages de confidentialité via Doctrine (US-035 T-035-04).
 *
 * findByUserId() : SELECT par user_id ; retourne les valeurs par défaut opt-in si 0 lignes.
 * save()         : upsert (INSERT ou UPDATE si entrée existante) via Doctrine Entity Manager.
 *
 * Implémente PrivacySettingsRepositoryInterface (port Domain).
 */
final class DoctrinePrivacySettingsRepository implements PrivacySettingsRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function findByUserId(string $userId): UserPrivacySettings
    {
        $entity = $this->em->find(DoctrineUserPrivacySettingsEntity::class, $userId);

        if (null === $entity) {
            // Aucune entrée → valeurs par défaut opt-in (nouveaux comptes ou migration Sprint 4)
            return UserPrivacySettings::defaults();
        }

        return $entity->toDomain();
    }

    public function save(string $userId, UserPrivacySettings $settings): void
    {
        $entity = $this->em->find(DoctrineUserPrivacySettingsEntity::class, $userId);

        if (null === $entity) {
            $entity = DoctrineUserPrivacySettingsEntity::fromDomain($userId, $settings);
            $this->em->persist($entity);
        } else {
            $entity->updateFrom($settings);
        }

        $this->em->flush();
    }
}
