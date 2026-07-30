<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * Port — Repository des utilisateurs.
 *
 * Interface dans le Domain (DIP : constitution §4).
 * Implémentation Doctrine dans Infrastructure.
 *
 * Constitution §4 : interfaces de repository dans le Domain,
 * implémentations dans Infrastructure.
 */
interface UserRepositoryInterface
{
    /**
     * Persiste un nouvel utilisateur ou met à jour un existant.
     */
    public function save(User $user): void;

    /**
     * Trouve un utilisateur par son adresse email.
     *
     * @return User|null null si aucun utilisateur ne correspond
     */
    public function findByEmail(Email $email): ?User;

    /**
     * Vérifie si un email est déjà utilisé.
     *
     * Optimisé pour l'unicité (évite de charger l'entité complète).
     */
    public function emailExists(Email $email): bool;

    /**
     * Trouve un utilisateur par son identifiant (UUID).
     *
     * @return User|null null si l'identifiant est inconnu
     */
    public function findById(string $id): ?User;

    /**
     * Trouve un utilisateur par son token de confirmation de changement d'email.
     *
     * @return User|null null si le token est inconnu ou expiré
     */
    public function findByEmailPendingToken(string $token): ?User;
}
