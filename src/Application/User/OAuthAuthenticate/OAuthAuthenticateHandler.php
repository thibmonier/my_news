<?php

declare(strict_types=1);

namespace App\Application\User\OAuthAuthenticate;

use App\Domain\User\Email;
use App\Domain\User\OAuthAccount;
use App\Domain\User\OAuthAccountRepositoryInterface;
use App\Domain\User\OAuthProvider;
use App\Domain\User\User;
use App\Domain\User\UserRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cas d'usage — Authentifier ou lier un utilisateur via OAuth.
 *
 * Règles métier US-031 :
 * 1. Lookup (provider, providerId) dans oauth_accounts → User existant.
 * 2. Sinon lookup users par email → liaison compte + création OAuthAccount (fusion sans doublon).
 * 3. Sinon création User sans mot de passe local + création OAuthAccount.
 *
 * Constitution §4 : Application dépend de Domain uniquement.
 * Les UUID v4 sont générés ici (couche Application/Infrastructure, pas Domain).
 *
 * @see OAuthAuthenticateCommand
 */
final readonly class OAuthAuthenticateHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private OAuthAccountRepositoryInterface $oauthAccountRepository,
    ) {
    }

    /**
     * @return array{user: User, isNew: bool}
     *                                        isNew = true si le User vient d'être créé (premier accès OAuth)
     */
    public function handle(OAuthAuthenticateCommand $command): array
    {
        $provider = new OAuthProvider($command->provider);

        // 1. Lookup par (provider, providerId) — connexion récurrente
        $existingOAuthAccount = $this->oauthAccountRepository->findByProviderAndId(
            $provider,
            $command->providerId,
        );

        if (null !== $existingOAuthAccount) {
            $user = $this->userRepository->findById($existingOAuthAccount->getUserId());

            if (null === $user) {
                throw new \RuntimeException(\sprintf('Incohérence de données : OAuthAccount %s référence un User inexistant %s.', $existingOAuthAccount->getId(), $existingOAuthAccount->getUserId()));
            }

            return ['user' => $user, 'isNew' => false];
        }

        // 2. Lookup par email — fusion de compte (compte email/password existant)
        $email = new Email($command->email);
        $existingUser = $this->userRepository->findByEmail($email);

        if (null !== $existingUser) {
            // Lier l'identité OAuth au compte existant (sans doublon User)
            $oauthAccount = new OAuthAccount(
                id: Uuid::v4()->toRfc4122(),
                userId: $existingUser->getId(),
                provider: $provider,
                providerId: $command->providerId,
                emailProvider: $command->email,
                createdAt: new \DateTimeImmutable(),
            );
            $this->oauthAccountRepository->save($oauthAccount);

            return ['user' => $existingUser, 'isNew' => false];
        }

        // 3. Création d'un nouveau compte (premier accès OAuth sans compte existant)
        // Mot de passe aléatoire non utilisable (hash d'un UUID aléatoire, jamais communiqué)
        // Le consentement RGPD est horodaté maintenant (première connexion OAuth)
        $now = new \DateTimeImmutable();
        $newUser = new User(
            id: Uuid::v4()->toRfc4122(),
            email: $email,
            passwordHash: 'oauth_' . bin2hex(random_bytes(32)), // non utilisable, jamais exposé
            fullName: '' !== $command->fullName ? $command->fullName : $command->email,
            createdAt: $now,
            consentAt: $now, // consentement RGPD horodaté à la première connexion OAuth
        );
        $this->userRepository->save($newUser);

        $oauthAccount = new OAuthAccount(
            id: Uuid::v4()->toRfc4122(),
            userId: $newUser->getId(),
            provider: $provider,
            providerId: $command->providerId,
            emailProvider: $command->email,
            createdAt: $now,
        );
        $this->oauthAccountRepository->save($oauthAccount);

        return ['user' => $newUser, 'isNew' => true];
    }
}
