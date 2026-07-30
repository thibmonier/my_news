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
        private Email $email,
        private string $passwordHash,
        private string $fullName,
        private readonly \DateTimeImmutable $createdAt,
        private readonly \DateTimeImmutable $consentAt,
        private readonly array $roles = ['ROLE_USER'],
        private ?string $bio = null,
        private ?string $emailPending = null,
        private ?string $emailPendingToken = null,
        private ?\DateTimeImmutable $emailPendingExpiresAt = null,
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

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function getEmailPending(): ?string
    {
        return $this->emailPending;
    }

    public function getEmailPendingToken(): ?string
    {
        return $this->emailPendingToken;
    }

    public function getEmailPendingExpiresAt(): ?\DateTimeImmutable
    {
        return $this->emailPendingExpiresAt;
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

    /**
     * Met à jour le profil de l'utilisateur (nom complet + bio).
     *
     * @throws \InvalidArgumentException si le nom est vide ou si la bio dépasse 280 caractères
     */
    public function updateProfile(string $fullName, ?string $bio): void
    {
        $fullName = trim($fullName);

        if ('' === $fullName) {
            throw new \InvalidArgumentException('Le nom complet ne peut pas être vide.');
        }

        if (mb_strlen($fullName) > 255) {
            throw new \InvalidArgumentException('Le nom complet ne peut pas dépasser 255 caractères.');
        }

        if (null !== $bio && mb_strlen($bio) > 280) {
            throw new \InvalidArgumentException('La bio ne peut pas dépasser 280 caractères.');
        }

        $this->fullName = $fullName;
        $this->bio = '' === $bio ? null : $bio;
    }

    /**
     * Initie un changement d'email (double opt-in).
     *
     * L'email courant (getEmail()) reste inchangé jusqu'à confirmEmailChange().
     */
    public function requestEmailChange(string $emailPending, string $token, \DateTimeImmutable $expiresAt): void
    {
        $this->emailPending = $emailPending;
        $this->emailPendingToken = $token;
        $this->emailPendingExpiresAt = $expiresAt;
    }

    /**
     * Confirme le changement d'email : remplace l'email courant et vide les champs temporaires.
     *
     * @throws \LogicException si aucun changement d'email n'est en attente
     */
    public function confirmEmailChange(): void
    {
        if (null === $this->emailPending) {
            throw new \LogicException('Aucun changement d\'email en attente.');
        }

        $this->email = new Email($this->emailPending);
        $this->emailPending = null;
        $this->emailPendingToken = null;
        $this->emailPendingExpiresAt = null;
    }
}
