<?php

declare(strict_types=1);

use App\Domain\User\Email;

/*
 * Unit tests — Email (Value Object domaine)
 *
 * Tests purement unitaires : aucune dépendance framework, aucun I/O.
 * Vérifie construction, normalisation, validation, équivalence.
 */

// ── Construction et normalisation ─────────────────────────────────────────────

test('Email normalise en minuscules', function (): void {
    $email = new Email('Thomas@EXAMPLE.COM');
    expect($email->getValue())->toBe('thomas@example.com');
});

test('Email supprime les espaces en début et fin', function (): void {
    $email = new Email('  thomas@example.com  ');
    expect($email->getValue())->toBe('thomas@example.com');
});

test('Email toString retourne la valeur normalisée', function (): void {
    $email = new Email('Thomas@Example.com');
    expect((string) $email)->toBe('thomas@example.com');
});

// ── Emails valides ─────────────────────────────────────────────────────────────

test('Email accepte un format email valide basique', function (): void {
    $email = new Email('user@example.com');
    expect($email->getValue())->toBe('user@example.com');
});

test('Email accepte un email avec sous-domaine', function (): void {
    $email = new Email('user@mail.example.co.uk');
    expect($email->getValue())->toBe('user@mail.example.co.uk');
});

test('Email accepte un email avec point dans la partie locale', function (): void {
    $email = new Email('first.last@example.com');
    expect($email->getValue())->toBe('first.last@example.com');
});

// ── Emails invalides ───────────────────────────────────────────────────────────

test('Email rejette une chaîne vide', function (): void {
    expect(static fn () => new Email(''))->toThrow(
        InvalidArgumentException::class,
        'vide',
    );
});

test('Email rejette une valeur sans @', function (): void {
    expect(static fn () => new Email('notanemail'))->toThrow(InvalidArgumentException::class);
});

test('Email rejette une valeur sans domaine', function (): void {
    expect(static fn () => new Email('user@'))->toThrow(InvalidArgumentException::class);
});

test('Email rejette une valeur trop longue (> 255 caractères)', function (): void {
    // 252 a's + @x.fr = 257 chars total → dépasse 255
    $longEmail = str_repeat('a', 30) . '@' . str_repeat('b', 30) . '.' . str_repeat('c', 200) . '.fr';
    expect(static fn () => new Email($longEmail))->toThrow(
        InvalidArgumentException::class,
        '255',
    );
});

// ── Équivalence ────────────────────────────────────────────────────────────────

test('Email equals retourne true pour des emails identiques (casse différente)', function (): void {
    $a = new Email('thomas@example.com');
    $b = new Email('Thomas@EXAMPLE.COM');
    expect($a->equals($b))->toBeTrue();
});

test('Email equals retourne false pour des emails différents', function (): void {
    $a = new Email('thomas@example.com');
    $b = new Email('priya@example.com');
    expect($a->equals($b))->toBeFalse();
});
