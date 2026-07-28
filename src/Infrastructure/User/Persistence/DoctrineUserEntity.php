<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Persistence;

use App\Domain\User\Email;
use App\Domain\User\IdentifiableUserInterface;
use App\Domain\User\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Entité Doctrine — représentation persistée d'un utilisateur.
 *
 * Réside dans la couche Infrastructure UNIQUEMENT.
 * Les attributs #[ORM] ne doivent JAMAIS apparaître dans src/Domain/ (constitution §4).
 *
 * Implémente UserInterface + PasswordAuthenticatedUserInterface pour l'intégration
 * avec Symfony Security (provider entity, form_login, session).
 *
 * UUID v7 (non séquentiel au sens séquence DB — constitution §6 + ADR-006).
 * email : UNIQUE NOT NULL (contrainte DB + unicité logique validée par le handler).
 * password_hash : haché Argon2id via libsodium (algorithm: sodium, security.yaml).
 * consent_at : horodatage UTC de l'acceptation des CGU — preuve légale RGPD.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
class DoctrineUserEntity implements UserInterface, PasswordAuthenticatedUserInterface, IdentifiableUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255, unique: true)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'full_name', length: 255)]
    private string $fullName;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'consent_at')]
    private \DateTimeImmutable $consentAt;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        Uuid $id,
        string $email,
        string $passwordHash,
        string $fullName,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $consentAt,
        array $roles = ['ROLE_USER'],
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->fullName = $fullName;
        $this->createdAt = $createdAt;
        $this->consentAt = $consentAt;
        /* @var list<string> $roles */
        $this->roles = $roles;
    }

    // ── Factories ──────────────────────────────────────────────────────────────

    /**
     * Crée une entité Doctrine à partir d'une entité domaine (anti-corruption layer).
     */
    public static function fromDomainEntity(User $user): self
    {
        return new self(
            id: Uuid::fromString($user->getId()),
            email: $user->getEmail()->getValue(),
            passwordHash: $user->getPasswordHash(),
            fullName: $user->getFullName(),
            createdAt: $user->getCreatedAt(),
            consentAt: $user->getConsentAt(),
            roles: $user->getRoles(),
        );
    }

    // ── UserInterface ──────────────────────────────────────────────────────────

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        // email is always non-empty (validated at registration and DB NOT NULL UNIQUE constraint)
        \assert('' !== $this->email);

        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        /* @var list<string> */
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function eraseCredentials(): void
    {
        // Le mot de passe en clair n'est jamais stocké ici.
        // Réinitialiser `$plainPassword` si on en avait un (aucun cas ici).
    }

    // ── PasswordAuthenticatedUserInterface ─────────────────────────────────────

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    // ── Getters ────────────────────────────────────────────────────────────────

    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Implémente IdentifiableUserInterface (Domain) — UUID en RFC 4122.
     *
     * Permet à la couche Presentation d'obtenir l'UUID sans dépendre
     * de DoctrineUserEntity (conformité deptrac).
     */
    public function getUserUuid(): string
    {
        return $this->id->toRfc4122();
    }

    public function getEmail(): string
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

    // ── Anti-corruption layer ──────────────────────────────────────────────────

    /**
     * Convertit en entité domaine.
     */
    public function toDomainEntity(): User
    {
        return new User(
            id: $this->id->toRfc4122(),
            email: new Email($this->email),
            passwordHash: $this->passwordHash,
            fullName: $this->fullName,
            createdAt: $this->createdAt,
            consentAt: $this->consentAt,
            roles: $this->roles,
        );
    }
}
