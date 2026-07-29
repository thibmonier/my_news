<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * Value Object — Provider OAuth supporté.
 *
 * PHP pur — aucun attribut ORM, aucun import Symfony.
 * Constitution §4 : entités Domain = classes PHP pures.
 */
final class OAuthProvider
{
    private const ALLOWED = ['google', 'github'];

    public function __construct(
        private readonly string $value,
    ) {
        if (!\in_array($value, self::ALLOWED, true)) {
            throw new \InvalidArgumentException(\sprintf('Provider OAuth invalide "%s". Valeurs autorisées : %s.', $value, implode(', ', self::ALLOWED)));
        }
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public static function google(): self
    {
        return new self('google');
    }

    public static function github(): self
    {
        return new self('github');
    }
}
