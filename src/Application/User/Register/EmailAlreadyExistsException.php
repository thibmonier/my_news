<?php

declare(strict_types=1);

namespace App\Application\User\Register;

/**
 * Exception domaine — Email déjà enregistré.
 *
 * Levée par RegisterUserHandler quand un email est déjà associé à un compte.
 *
 * OWASP #3 : le message n'indique pas le type de compte (email vs OAuth)
 * pour éviter toute fuite d'information (scénario alternatif 2 de US-030).
 */
final class EmailAlreadyExistsException extends \DomainException
{
    public function __construct(string $email)
    {
        parent::__construct(
            \sprintf('Un compte est déjà associé à l\'email "%s".', $email),
        );
    }
}
