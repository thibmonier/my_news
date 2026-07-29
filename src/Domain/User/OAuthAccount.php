<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * Entité domaine — Compte OAuth lié à un utilisateur.
 *
 * PHP pur — aucun attribut ORM, aucun import Symfony.
 * Constitution §4 : entités Domain = classes PHP pures.
 *
 * Représente la liaison entre un utilisateur Briefly AI et une identité
 * fédérée chez un provider OAuth (Google ou GitHub).
 *
 * Les access_tokens provider ne sont JAMAIS persistés ici (exigence RGPD) :
 * ils ne transitent qu'en session Symfony pour la durée de la session.
 *
 * consent_at : horodatage UTC du premier consentement RGPD lors de la
 * première connexion OAuth (preuve légale, analogie US-030 consent_at).
 */
final class OAuthAccount
{
    public function __construct(
        private readonly string $id,
        private readonly string $userId,
        private readonly OAuthProvider $provider,
        private readonly string $providerId,
        private readonly string $emailProvider,
        private readonly \DateTimeImmutable $createdAt,
    ) {
        if ('' === trim($providerId)) {
            throw new \InvalidArgumentException('Le providerId OAuth ne peut pas être vide.');
        }

        if ('' === trim($emailProvider)) {
            throw new \InvalidArgumentException("L'email provider OAuth ne peut pas être vide.");
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getProvider(): OAuthProvider
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
