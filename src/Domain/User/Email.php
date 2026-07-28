<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * Value Object — Adresse email validée et normalisée.
 *
 * PHP pur — AUCUN attribut #[ORM], AUCUN import Symfony/Doctrine.
 * Constitution §4 : Value Objects immuables pour les concepts métier.
 *
 * La normalisation (lowercase + trim) garantit l'unicité logique en base.
 */
final class Email
{
    private readonly string $value;

    /**
     * @throws \InvalidArgumentException si l'adresse email est invalide ou trop longue
     */
    public function __construct(string $value)
    {
        $normalized = mb_strtolower(trim($value));

        if ('' === $normalized) {
            throw new \InvalidArgumentException('L\'adresse email ne peut pas être vide.');
        }

        // Longueur vérifiée AVANT filter_var (qui peut tronquer silencieusement)
        if (mb_strlen($normalized) > 255) {
            throw new \InvalidArgumentException(\sprintf('L\'adresse email ne peut pas dépasser 255 caractères (reçu : %d).', mb_strlen($normalized)));
        }

        if (false === filter_var($normalized, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(\sprintf('L\'adresse email "%s" est invalide.', $normalized));
        }

        $this->value = $normalized;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
