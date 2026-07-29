<?php

declare(strict_types=1);

namespace App\Application\User;

/**
 * Port — Hachage de mot de passe.
 *
 * Interface dans la couche Application (port secondaire).
 * Implémentation dans Infrastructure (SymfonyPasswordHasher → Argon2id via libsodium).
 *
 * Constitution §6 : Argon2id (128 MiB, t=3, p=1) — jamais MD5/SHA1/bcrypt.
 * Le mot de passe en clair est marqué SensitiveParameter pour ne jamais apparaître
 * dans les logs/stack traces (OWASP #7 + #9).
 */
interface PasswordHasherInterface
{
    /**
     * Hache un mot de passe en clair avec Argon2id.
     *
     * @throws \RuntimeException si le hachage échoue
     */
    public function hash(#[\SensitiveParameter] string $plainPassword): string;

    /**
     * Vérifie qu'un mot de passe en clair correspond à un hash.
     */
    public function verify(string $hash, #[\SensitiveParameter] string $plainPassword): bool;
}
