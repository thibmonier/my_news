<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * Port — Repository des comptes OAuth.
 *
 * Interface dans le Domain (DIP : constitution §4).
 * Implémentation Doctrine dans Infrastructure.
 */
interface OAuthAccountRepositoryInterface
{
    /**
     * Persiste un nouveau compte OAuth.
     */
    public function save(OAuthAccount $account): void;

    /**
     * Trouve un compte OAuth par provider + identifiant provider.
     *
     * @return OAuthAccount|null null si aucun compte ne correspond
     */
    public function findByProviderAndId(OAuthProvider $provider, string $providerId): ?OAuthAccount;

    /**
     * Trouve le premier compte OAuth lié à un utilisateur pour un provider donné.
     *
     * @return OAuthAccount|null null si aucun compte ne correspond
     */
    public function findByUserIdAndProvider(string $userId, OAuthProvider $provider): ?OAuthAccount;
}
