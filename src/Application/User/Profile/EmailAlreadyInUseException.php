<?php

declare(strict_types=1);

namespace App\Application\User\Profile;

/**
 * Exception — L'adresse email est déjà associée à un autre compte.
 *
 * Levée par EmailChangeService quand le nouvel email est déjà utilisé
 * par un utilisateur différent de celui qui fait la demande.
 *
 * Ne pas inclure l'email dans le message d'exception (RGPD — logs).
 */
final class EmailAlreadyInUseException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Cette adresse email est déjà associée à un compte Briefly AI.');
    }
}
