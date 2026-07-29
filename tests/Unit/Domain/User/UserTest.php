<?php

declare(strict_types=1);

use App\Domain\User\Email;
use App\Domain\User\User;

/*
 * Unit tests — User (entité domaine)
 *
 * Tests purement unitaires : aucune dépendance framework, aucun I/O.
 * Vérifie construction, getters, rôles et mutation du hash.
 *
 * Couvre T-030-11 : UUID, consent_at non null, hash non vide.
 */

// ── Helpers ────────────────────────────────────────────────────────────────────

function makeUser(array $overrides = []): User
{
    $defaults = [
        'id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
        'email' => new Email('thomas@example.com'),
        'passwordHash' => '$argon2id$v=19$m=65536,t=3,p=1$fakehash',
        'fullName' => 'Thomas Dupont',
        'createdAt' => new DateTimeImmutable('2026-07-28 10:00:00', new DateTimeZone('UTC')),
        'consentAt' => new DateTimeImmutable('2026-07-28 10:00:00', new DateTimeZone('UTC')),
    ];

    $data = array_merge($defaults, $overrides);

    return new User(
        id: $data['id'],
        email: $data['email'],
        passwordHash: $data['passwordHash'],
        fullName: $data['fullName'],
        createdAt: $data['createdAt'],
        consentAt: $data['consentAt'],
    );
}

// ── Construction et getters ────────────────────────────────────────────────────

test('User expose son identifiant', function (): void {
    $user = makeUser(['id' => 'test-uuid-001']);
    expect($user->getId())->toBe('test-uuid-001');
});

test('User expose son email via Value Object', function (): void {
    $email = new Email('thomas@example.com');
    $user = makeUser(['email' => $email]);
    expect($user->getEmail())->toBe($email)
        ->and($user->getEmail()->getValue())->toBe('thomas@example.com');
});

test('User expose son hash de mot de passe (non vide)', function (): void {
    $hash = '$argon2id$v=19$m=65536,t=3,p=1$realargon2idhash';
    $user = makeUser(['passwordHash' => $hash]);
    expect($user->getPasswordHash())->toBe($hash)
        ->and($user->getPasswordHash())->not->toBeEmpty();
});

test('User expose son nom complet', function (): void {
    $user = makeUser(['fullName' => 'Thomas Dupont']);
    expect($user->getFullName())->toBe('Thomas Dupont');
});

test('User expose createdAt en UTC', function (): void {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $user = makeUser(['createdAt' => $now]);
    expect($user->getCreatedAt()->getTimezone()->getName())->toBe('UTC');
});

test('User expose consentAt non null (preuve légale RGPD)', function (): void {
    $consentAt = new DateTimeImmutable('2026-07-28 10:00:00', new DateTimeZone('UTC'));
    $user = makeUser(['consentAt' => $consentAt]);
    expect($user->getConsentAt())->not->toBeNull()
        ->and($user->getConsentAt()->getTimezone()->getName())->toBe('UTC');
});

// ── Rôles ──────────────────────────────────────────────────────────────────────

test('User inclut toujours ROLE_USER dans ses rôles', function (): void {
    $user = makeUser();
    expect($user->getRoles())->toContain('ROLE_USER');
});

test('User getRoles retourne une liste sans doublons', function (): void {
    $user = makeUser();
    $roles = $user->getRoles();
    expect($roles)->toBe(array_values(array_unique($roles)));
});

// ── Mutation du hash ───────────────────────────────────────────────────────────

test('User.changePasswordHash met à jour le hash', function (): void {
    $user = makeUser(['passwordHash' => 'old_hash']);
    $newHash = '$argon2id$v=19$m=65536,t=3,p=1$newhash';
    $user->changePasswordHash($newHash);
    expect($user->getPasswordHash())->toBe($newHash);
});

test('User.changePasswordHash rejette un hash vide', function (): void {
    $user = makeUser();
    expect(static fn () => $user->changePasswordHash(''))->toThrow(
        InvalidArgumentException::class,
    );
});
