<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Security;

use App\Domain\Feed\Source;
use App\Domain\Feed\SourcePermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Voter Symfony — Contrôle d'accès aux opérations admin sur les Sources.
 *
 * Toutes les opérations nécessitent ROLE_ADMIN (deny by default — constitution §6).
 * Utilisé par AdminSourceController pour chaque action CRUD.
 *
 * Attributs supportés : SourcePermission::CREATE/EDIT/DELETE/TOGGLE/BULK.
 * Les constantes sont dans App\Domain\Feed\SourcePermission (accès Presentation + Infrastructure).
 *
 * Couche Infrastructure (accès à Symfony Security) — Deptrac: Infrastructure:[Domain, Application].
 *
 * @extends Voter<string, Source|null>
 */
final class SourceVoter extends Voter
{
    /** @var list<string> */
    private const SUPPORTED_ATTRIBUTES = [
        SourcePermission::CREATE,
        SourcePermission::EDIT,
        SourcePermission::DELETE,
        SourcePermission::TOGGLE,
        SourcePermission::BULK,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)
            && ($subject instanceof Source || null === $subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Non authentifié → refus
        if (!$user instanceof UserInterface) {
            return false;
        }

        // Deny by default : ROLE_ADMIN requis pour toutes les opérations
        return \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
