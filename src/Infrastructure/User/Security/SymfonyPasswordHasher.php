<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Security;

use App\Application\User\PasswordHasherInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Adapter — Hachage de mot de passe via Symfony Password Hasher.
 *
 * Implémente PasswordHasherInterface (port Application) en déléguant
 * au PasswordHasherFactory configuré dans security.yaml.
 *
 * Constitution §6 :
 * - Algorithme : sodium (Argon2id via libsodium) — configuré dans security.yaml
 * - Jamais MD5/SHA1/bcrypt en nouveau code
 * - Mot de passe en clair marqué #[\SensitiveParameter] (OWASP #7 + #9)
 *
 * Deptrac : Infrastructure → Application (PasswordHasherInterface).
 */
final class SymfonyPasswordHasher implements PasswordHasherInterface
{
    public function __construct(
        private readonly PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        return $this->hasherFactory
            ->getPasswordHasher(PasswordAuthenticatedUserInterface::class)
            ->hash($plainPassword);
    }

    public function verify(string $hash, #[\SensitiveParameter] string $plainPassword): bool
    {
        return $this->hasherFactory
            ->getPasswordHasher(PasswordAuthenticatedUserInterface::class)
            ->verify($hash, $plainPassword);
    }
}
