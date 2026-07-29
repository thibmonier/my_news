<?php

declare(strict_types=1);

namespace App\Application\User\OAuthAuthenticate;

/**
 * Commande — Authentifier ou lier un utilisateur via OAuth.
 *
 * Cas d'usage US-031 :
 * - Si (provider, providerId) existe → retourne le User lié.
 * - Si email existe dans users → lie le compte OAuth + retourne le User existant.
 * - Sinon → crée un nouveau User (sans mot de passe local utilisable) + OAuthAccount.
 *
 * Les access_tokens ne sont JAMAIS présents ici (exigence RGPD).
 * Le consentement RGPD est horodaté à la première connexion OAuth.
 */
final readonly class OAuthAuthenticateCommand
{
    public function __construct(
        public readonly string $provider,
        public readonly string $providerId,
        public readonly string $email,
        public readonly string $fullName,
    ) {
    }
}
