<?php

declare(strict_types=1);

namespace App\Presentation\Security;

use App\Domain\User\IdentifiableUserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter Symfony — Contrôle d'accès à l'édition des réglages de confidentialité (US-035 T-035-06).
 *
 * Attribut supporté : PrivacySettingsVoter::EDIT
 * Sujet attendu     : IdentifiableUserInterface (l'utilisateur cible)
 *
 * Logique (deny by default) :
 *  - L'utilisateur courant (token) doit implémenter IdentifiableUserInterface.
 *  - Le sujet doit implémenter IdentifiableUserInterface.
 *  - Les deux UUIDs doivent correspondre (propriétaire uniquement — anti-IDOR).
 *
 * DENY → log WARNING avec requester_uuid + target_uuid UNIQUEMENT (RGPD : jamais d'email).
 *
 * Pattern identique à ProfileVoter (US-032).
 * Couche Presentation — dépend de Domain uniquement (deptrac conforme).
 *
 * @extends Voter<string, IdentifiableUserInterface>
 */
final class PrivacySettingsVoter extends Voter
{
    public const EDIT = 'PRIVACY_SETTINGS_EDIT';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof IdentifiableUserInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Non authentifié ou user sans UUID → refus
        if (!$user instanceof IdentifiableUserInterface) {
            return false;
        }

        // Vérification propriétaire : UUIDs doivent correspondre (anti-IDOR)
        if ($user->getUserUuid() === $subject->getUserUuid()) {
            return true;
        }

        // Accès non autorisé → log WARNING (RGPD : UUID uniquement, JAMAIS email)
        $this->logger->warning('privacy.unauthorized_edit_attempt', [
            'event' => 'unauthorized_privacy_edit',
            'requester_id' => $user->getUserUuid(),
            'target_id' => $subject->getUserUuid(),
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::RFC3339),
            'action' => 'unauthorized_privacy_edit',
        ]);

        return false;
    }
}
