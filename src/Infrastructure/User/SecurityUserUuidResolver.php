<?php

declare(strict_types=1);

namespace App\Infrastructure\User;

use App\Application\Quota\UserUuidResolverInterface;
use App\Domain\User\IdentifiableUserInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Adapter — Résolution de l'UUID utilisateur via Symfony Security.
 *
 * Implémente UserUuidResolverInterface (port Application) en s'appuyant
 * sur Symfony Security pour extraire l'UUID de l'utilisateur connecté.
 *
 * Extrait l'UUID via IdentifiableUserInterface (Domain) pour ne pas
 * dépendre directement de DoctrineUserEntity (conformité deptrac).
 *
 * Retourne null si :
 * - Aucun utilisateur n'est authentifié (session anonyme)
 * - L'utilisateur n'implémente pas IdentifiableUserInterface
 *
 * Deptrac : Infrastructure → Domain (IdentifiableUserInterface)
 *           Infrastructure → Application (UserUuidResolverInterface)
 */
final class SecurityUserUuidResolver implements UserUuidResolverInterface
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    /**
     * Retourne l'UUID de l'utilisateur connecté, ou null si non identifiable.
     */
    public function getCurrentUserUuid(): ?string
    {
        $user = $this->security->getUser();

        if (!$user instanceof IdentifiableUserInterface) {
            return null;
        }

        return $user->getUserUuid();
    }
}
