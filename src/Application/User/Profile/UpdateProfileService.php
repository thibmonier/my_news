<?php

declare(strict_types=1);

namespace App\Application\User\Profile;

use App\Domain\User\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service Application — Mise à jour du profil utilisateur (nom + bio).
 *
 * Ne gère PAS le changement d'email (voir EmailChangeService — double opt-in).
 *
 * Couche Application — dépend de Domain uniquement (deptrac).
 * Orchestre : chargement User → mutation → persistence.
 *
 * Logs INFO à chaque mise à jour de profil.
 */
final class UpdateProfileService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Met à jour le nom complet et la bio d'un utilisateur.
     *
     * @param string $userId UUID RFC 4122 de l'utilisateur
     * @param string $fullName Nouveau nom complet (1-255 caractères)
     * @param ?string $bio Nouvelle bio (0-280 caractères), null pour effacer
     *
     * @throws \RuntimeException si l'utilisateur est introuvable
     * @throws \InvalidArgumentException si les valeurs sont invalides (propagée depuis User::updateProfile)
     */
    public function execute(string $userId, string $fullName, ?string $bio): void
    {
        $user = $this->userRepository->findById($userId);

        if (null === $user) {
            throw new \RuntimeException(\sprintf('Utilisateur introuvable : %s', $userId));
        }

        $user->updateProfile($fullName, $bio);
        $this->userRepository->save($user);

        $this->logger->info('profile.updated', [
            'event' => 'profile.updated',
            'user_uuid' => $userId,
        ]);
    }
}
