<?php

declare(strict_types=1);

namespace App\Application\User\Register;

use App\Application\User\PasswordHasherInterface;
use App\Domain\User\Email;
use App\Domain\User\User;
use App\Domain\User\UserRepositoryInterface;

/**
 * Handler — Use case d'inscription d'un nouvel utilisateur.
 *
 * Orchestre la création d'un compte :
 * 1. Valide l'unicité de l'email (via le repository)
 * 2. Hache le mot de passe (via le port PasswordHasherInterface → Argon2id)
 * 3. Crée l'entité domaine User
 * 4. Persiste via UserRepositoryInterface
 *
 * Constitution §4 : pas de logique infrastructure ici — uniquement des interfaces.
 * Deptrac : Application → Domain uniquement.
 *
 * @throws EmailAlreadyExistsException si l'email est déjà enregistré
 */
final class RegisterUserHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Exécute le use case d'inscription.
     *
     * @throws EmailAlreadyExistsException si l'email est déjà utilisé
     * @throws \InvalidArgumentException si l'email ou le mot de passe sont invalides
     */
    public function handle(RegisterUserCommand $command): User
    {
        $email = new Email($command->email);

        if ($this->userRepository->emailExists($email)) {
            throw new EmailAlreadyExistsException($email->getValue());
        }

        $passwordHash = $this->passwordHasher->hash($command->plainPassword);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $user = new User(
            id: $command->userId,
            email: $email,
            passwordHash: $passwordHash,
            fullName: $command->fullName,
            createdAt: $now,
            consentAt: $now,
        );

        $this->userRepository->save($user);

        return $user;
    }
}
