<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Value Object — Empreinte SHA-256 d'une URL canonique d'article.
 *
 * Immuable par conception (constructeur privé + named constructors).
 * La déduplication repose sur cette empreinte : deux articles avec la
 * même URL canonique partagent le même ContentHash et ne sont insérés
 * qu'une seule fois en base (contrainte UNIQUE sur content_hash).
 *
 * PHP pur — aucune dépendance framework (constitution §4, deptrac Domain:[]).
 */
final class ContentHash
{
    /** @var string 64 caractères hexadécimaux (SHA-256) */
    private readonly string $value;

    private function __construct(string $value)
    {
        if (64 !== \strlen($value) || !ctype_xdigit($value)) {
            throw new \InvalidArgumentException(\sprintf('ContentHash value must be 64 hex characters, got "%s" (%d chars).', $value, \strlen($value)));
        }
        $this->value = $value;
    }

    /**
     * Calcule le SHA-256 de l'URL canonique fournie.
     *
     * @param non-empty-string $canonicalUrl URL normalisée (sans UTM, fragment, trailing slash)
     */
    public static function fromCanonicalUrl(string $canonicalUrl): self
    {
        return new self(hash('sha256', $canonicalUrl));
    }

    /**
     * Reconstruit le Value Object depuis sa représentation stockée (64 hex chars).
     *
     * Utilisé par les repositories lors de la reconstruction depuis la base de données.
     *
     * @param non-empty-string $hash
     */
    public static function fromStoredHash(string $hash): self
    {
        return new self(strtolower($hash));
    }

    /** @return non-empty-string 64 caractères hexadécimaux */
    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
