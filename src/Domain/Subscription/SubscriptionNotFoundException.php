<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

/**
 * Levée quand aucun abonnement actif n'est trouvé pour un utilisateur.
 * Exemple : createPortalSession() appelé pour un compte Free.
 */
final class SubscriptionNotFoundException extends \DomainException
{
    public static function forUser(string $userUuid): self
    {
        return new self(\sprintf('No active subscription found for user %s.', $userUuid));
    }
}
