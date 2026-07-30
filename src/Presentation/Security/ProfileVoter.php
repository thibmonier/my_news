<?php

declare(strict_types=1);

namespace App\Presentation\Security;

use App\Domain\User\IdentifiableUserInterface;
use App\Domain\User\UserProfileInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter Symfony — Contrôle d'accès à l'édition du profil utilisateur (US-032).
 *
 * Attribut supporté : ProfileVoter::EDIT
 * Sujet attendu     : UserProfileInterface (implémenté par DoctrineUserEntity)
 *
 * Logique (deny by default — constitution §6) :
 *  - L'utilisateur courant (token) doit implémenter IdentifiableUserInterface.
 *  - Le sujet doit implémenter UserProfileInterface.
 *  - Les deux UUIDs doivent correspondre (propriétaire uniquement).
 *
 * DENY → log WARN avec requester_uuid + target_uuid (jamais d'email — RGPD).
 *
 * Couche Presentation — dépend de Domain uniquement (deptrac conforme).
 *
 * @extends Voter<string, UserProfileInterface>
 */
final class ProfileVoter extends Voter
{
    public const EDIT = 'PROFILE_EDIT';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof UserProfileInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Non authentifié ou user sans UUID → refus
        if (!$user instanceof IdentifiableUserInterface) {
            return false;
        }

        // Vérification propriétaire : UUIDs doivent correspondre
        if ($user->getUserUuid() === $subject->getUserUuid()) {
            return true;
        }

        // Accès non autorisé → log WARN (RGPD : jamais d'email, UUIDs seulement)
        $this->logger->warning('profile.unauthorized_edit_attempt', [
            'event' => 'profile.unauthorized_edit_attempt',
            'requester_uuid' => $user->getUserUuid(),
            'target_uuid' => $subject->getUserUuid(),
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeImmutable::RFC3339),
        ]);

        return false;
    }
}
