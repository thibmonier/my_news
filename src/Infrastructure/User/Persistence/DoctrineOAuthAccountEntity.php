<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Persistence;

use App\Domain\User\OAuthAccount;
use App\Domain\User\OAuthProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entité Doctrine — représentation persistée d'un compte OAuth.
 *
 * Réside dans la couche Infrastructure UNIQUEMENT.
 * Les attributs #[ORM] ne doivent JAMAIS apparaître dans src/Domain/ (constitution §4).
 *
 * Table : oauth_accounts
 * UUID v4 (non séquentiel — constitution §6 + ADR-006).
 * provider : ENUM('google', 'github') — contrainte CHECK + application.
 * UNIQUE : (provider, provider_id) — garantit 0 doublon par provider.
 * FK user_id → users.id ON DELETE CASCADE (si l'utilisateur est supprimé).
 *
 * Les access_tokens provider ne sont JAMAIS persistés ici (exigence RGPD).
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_accounts')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_provider_id', columns: ['provider', 'provider_id'])]
#[ORM\Index(name: 'idx_oauth_provider', columns: ['provider'])]
#[ORM\Index(name: 'idx_oauth_email_provider', columns: ['email_provider'])]
class DoctrineOAuthAccountEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * FK → users.id ON DELETE CASCADE.
     * Relation ManyToOne non déclarée pour garder l'infrastructure simple
     * (pas de lazy loading inutile — jointure explicite si besoin).
     */
    #[ORM\Column(name: 'user_id', type: 'uuid')]
    private Uuid $userId;

    /**
     * Provider OAuth : 'google' ou 'github'.
     * Contrainte CHECK définie dans la migration (US-031 T-031-02).
     */
    #[ORM\Column(length: 32)]
    private string $provider;

    /**
     * Identifiant unique de l'utilisateur chez le provider (sub Google, id GitHub).
     */
    #[ORM\Column(name: 'provider_id', length: 255)]
    private string $providerId;

    /**
     * Email retourné par le provider (peut être noreply pour GitHub privacy mode).
     */
    #[ORM\Column(name: 'email_provider', length: 255)]
    private string $emailProvider;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $id,
        Uuid $userId,
        string $provider,
        string $providerId,
        string $emailProvider,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->provider = $provider;
        $this->providerId = $providerId;
        $this->emailProvider = $emailProvider;
        $this->createdAt = $createdAt;
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * Crée une entité Doctrine à partir d'une entité domaine (anti-corruption layer).
     */
    public static function fromDomainEntity(OAuthAccount $account): self
    {
        return new self(
            id: Uuid::fromString($account->getId()),
            userId: Uuid::fromString($account->getUserId()),
            provider: $account->getProvider()->getValue(),
            providerId: $account->getProviderId(),
            emailProvider: $account->getEmailProvider(),
            createdAt: $account->getCreatedAt(),
        );
    }

    // ── Anti-corruption layer ─────────────────────────────────────────────────

    /**
     * Convertit en entité domaine.
     */
    public function toDomainEntity(): OAuthAccount
    {
        return new OAuthAccount(
            id: $this->id->toRfc4122(),
            userId: $this->userId->toRfc4122(),
            provider: new OAuthProvider($this->provider),
            providerId: $this->providerId,
            emailProvider: $this->emailProvider,
            createdAt: $this->createdAt,
        );
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function getEmailProvider(): string
    {
        return $this->emailProvider;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
