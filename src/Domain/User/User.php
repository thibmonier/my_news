<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * Entité domaine — Utilisateur de la plateforme Briefly AI.
 *
 * PHP pur — AUCUN attribut #[ORM], AUCUN import Symfony/Doctrine.
 * Constitution §4 : entités Domain = classes PHP pures.
 *
 * L'identifiant est un UUID v7 (généré dans la couche Infrastructure/Presentation).
 * Le mot de passe est déjà haché (Argon2id) — jamais de mot de passe en clair ici.
 * consent_at = preuve légale RGPD du consentement aux CGU lors de l'inscription.
 */
final class User
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly string $id,
        private readonly Email $email,
        private string $passwordHash,
        private readonly string $fullName,
        private readonly \DateTimeImmutable $createdAt,
        private readonly \DateTimeImmutable $consentAt,
        private readonly array $roles = ['ROLE_USER'],
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getConsentAt(): \DateTimeImmutable
    {
        return $this->consentAt;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        /* @var list<string> */
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /**
     * Met à jour le hash du mot de passe (utilisé lors du premier hachage ou d'un changement).
     * Aucun mot de passe en clair ne transite jamais par cette entité.
     */
    public function changePasswordHash(string $newHash): void
    {
        if ('' === $newHash) {
            throw new \InvalidArgumentException('Le hash du mot de passe ne peut pas être vide.');
        }
        $this->passwordHash = $newHash;
    }
}
