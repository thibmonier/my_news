<?php

declare(strict_types=1);

use App\Domain\Feed\ContentHash;

/*
 * Unit tests — ContentHash (Value Object domaine)
 *
 * Tests purement unitaires : aucune dépendance framework, aucun I/O.
 * Couvre : calcul SHA-256, immutabilité, reconstruction depuis base, equality.
 */

test('ContentHash calcule un SHA-256 de 64 chars hexadécimaux depuis une URL canonique', function (): void {
    $hash = ContentHash::fromCanonicalUrl('https://techcrunch.com/2026/01/15/some-article');

    expect($hash->getValue())->toHaveLength(64)
        ->and(ctype_xdigit($hash->getValue()))->toBeTrue();
});

test('ContentHash retourne le même hash pour la même URL canonique', function (): void {
    $url = 'https://techcrunch.com/2026/01/15/some-article';

    $hash1 = ContentHash::fromCanonicalUrl($url);
    $hash2 = ContentHash::fromCanonicalUrl($url);

    expect($hash1->equals($hash2))->toBeTrue()
        ->and($hash1->getValue())->toBe($hash2->getValue());
});

test('ContentHash retourne des hashes différents pour des URLs différentes', function (): void {
    $hash1 = ContentHash::fromCanonicalUrl('https://techcrunch.com/2026/01/article-a');
    $hash2 = ContentHash::fromCanonicalUrl('https://techcrunch.com/2026/01/article-b');

    expect($hash1->equals($hash2))->toBeFalse();
});

test('ContentHash peut être reconstruit depuis un hash stocké valide', function (): void {
    $original = ContentHash::fromCanonicalUrl('https://example.com/article');
    $stored = $original->getValue();
    $recovered = ContentHash::fromStoredHash($stored);

    expect($recovered->equals($original))->toBeTrue();
});

test('ContentHash fromStoredHash rejette une valeur non hexadécimale de 64 chars', function (): void {
    expect(static fn () => ContentHash::fromStoredHash('not-a-valid-hash'))
        ->toThrow(InvalidArgumentException::class);
});

test('ContentHash fromStoredHash rejette un hash trop court', function (): void {
    expect(static fn () => ContentHash::fromStoredHash(str_repeat('a', 63)))
        ->toThrow(InvalidArgumentException::class);
});

test('ContentHash __toString retourne la valeur hexadécimale', function (): void {
    $hash = ContentHash::fromCanonicalUrl('https://example.com');

    expect((string) $hash)->toBe($hash->getValue());
});

test('ContentHash normalise la casse via fromStoredHash', function (): void {
    $upperHash = strtoupper(hash('sha256', 'https://example.com'));
    $hash = ContentHash::fromStoredHash($upperHash);

    // ctype_lower est faux pour les chiffres hex — on vérifie l'absence de majuscules
    expect($hash->getValue())->toBe(strtolower($upperHash));
});
