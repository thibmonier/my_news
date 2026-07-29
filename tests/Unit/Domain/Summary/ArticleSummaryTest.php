<?php

declare(strict_types=1);

use App\Domain\Summary\ArticleSummary;

/*
 * Unit tests — ArticleSummary Value Object (US-004/T-004-11).
 *
 * Valide les invariants du constructeur :
 * - Condensé nominal : 3 ≤ count(keyPoints) ≤ 4, chacune ≤ 120 chars
 * - Condensé dégradé : keyPoints vide, degradedContent ≤ 280 chars
 *
 * Gherkin validé : US-004 scénario nominal + alternatifs + erreurs.
 */

// ── Cas nominal : condensé IA valide ─────────────────────────────────────────

test('ArticleSummary nominal accepte 3 puces ≤ 120 chars', function (): void {
    $summary = new ArticleSummary(
        articleId: 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        keyPoints: [
            'Premier point clé de l\'article.',
            'Deuxième point clé important.',
            'Troisième point de conclusion.',
        ],
        modelVersion: 'mistral-small-latest',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
    );

    expect($summary->keyPoints)->toHaveCount(3)
        ->and($summary->isDegraded)->toBeFalse()
        ->and($summary->modelVersion)->toBe('mistral-small-latest');
});

test('ArticleSummary nominal accepte 4 puces ≤ 120 chars', function (): void {
    $summary = new ArticleSummary(
        articleId: 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        keyPoints: [
            'Premier point clé.',
            'Deuxième point clé.',
            'Troisième point clé.',
            'Quatrième point clé.',
        ],
        modelVersion: 'gpt-4o-mini',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
    );

    expect($summary->keyPoints)->toHaveCount(4)
        ->and($summary->isDegraded)->toBeFalse();
});

test('ArticleSummary expose articleId et modelVersion correctement', function (): void {
    $id = 'a1b2c3d4-e5f6-4789-abcd-ef0123456789';
    $summary = new ArticleSummary(
        articleId: $id,
        keyPoints: ['Point 1.', 'Point 2.', 'Point 3.'],
        modelVersion: 'mistral-small-latest',
        createdAt: new DateTimeImmutable('2026-07-29T12:00:00Z'),
    );

    expect($summary->articleId)->toBe($id)
        ->and($summary->modelVersion)->toBe('mistral-small-latest')
        ->and($summary->createdAt->format('Y'))->toBe('2026');
});

// ── Cas dégradé ───────────────────────────────────────────────────────────────

test('ArticleSummary dégradé accepte keyPoints vide et degradedContent ≤ 280 chars', function (): void {
    $summary = new ArticleSummary(
        articleId: 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        keyPoints: [],
        modelVersion: '',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        isDegraded: true,
        degradedContent: 'Extrait RSS brut de l\'article.',
    );

    expect($summary->isDegraded)->toBeTrue()
        ->and($summary->keyPoints)->toBeEmpty()
        ->and($summary->degradedContent)->toBe('Extrait RSS brut de l\'article.');
});

test('ArticleSummary dégradé avec degradedContent exactement 280 chars', function (): void {
    $content = str_repeat('A', ArticleSummary::MAX_DEGRADED_CONTENT_LENGTH);

    $summary = new ArticleSummary(
        articleId: 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        keyPoints: [],
        modelVersion: '',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        isDegraded: true,
        degradedContent: $content,
    );

    expect($summary->isDegraded)->toBeTrue()
        ->and(mb_strlen($summary->degradedContent))->toBe(ArticleSummary::MAX_DEGRADED_CONTENT_LENGTH);
});

// ── Violations des invariants ─────────────────────────────────────────────────

test('ArticleSummary lève InvalidArgumentException avec 2 puces (< 3)', function (): void {
    expect(static fn () => new ArticleSummary(
        articleId: 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        keyPoints: ['Point 1.', 'Point 2.'],
        modelVersion: 'mistral-small-latest',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ))->toThrow(InvalidArgumentException::class);
});

test('ArticleSummary lève InvalidArgumentException avec 5 puces (> 4)', function (): void {
    expect(static fn () => new ArticleSummary(
        articleId: 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        keyPoints: ['1.', '2.', '3.', '4.', '5.'],
        modelVersion: 'mistral-small-latest',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ))->toThrow(InvalidArgumentException::class);
});

test('ArticleSummary lève InvalidArgumentException si une puce dépasse 120 chars', function (): void {
    $longBullet = str_repeat('A', ArticleSummary::MAX_KEY_POINT_LENGTH + 1);

    expect(static fn () => new ArticleSummary(
        articleId: 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        keyPoints: [$longBullet, 'Point 2.', 'Point 3.'],
        modelVersion: 'mistral-small-latest',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ))->toThrow(InvalidArgumentException::class);
});

test('ArticleSummary lève InvalidArgumentException si puce exactement 121 chars', function (): void {
    $bullet = str_repeat('B', 121);

    expect(static fn () => new ArticleSummary(
        articleId: 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        keyPoints: [$bullet, 'Ok.', 'Ok.'],
        modelVersion: 'mistral-small-latest',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ))->toThrow(InvalidArgumentException::class);
});

test('ArticleSummary puce exactement 120 chars est valide', function (): void {
    $bullet = str_repeat('C', ArticleSummary::MAX_KEY_POINT_LENGTH);
    $summary = new ArticleSummary(
        articleId: 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        keyPoints: [$bullet, 'Point 2.', 'Point 3.'],
        modelVersion: 'mistral-small-latest',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
    );

    expect($summary->keyPoints[0])->toHaveLength(120);
});

test('ArticleSummary dégradé lève InvalidArgumentException si keyPoints non vide', function (): void {
    expect(static fn () => new ArticleSummary(
        articleId: 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        keyPoints: ['Point 1.'],
        modelVersion: '',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        isDegraded: true,
        degradedContent: 'Extrait.',
    ))->toThrow(InvalidArgumentException::class);
});

test('ArticleSummary dégradé lève InvalidArgumentException si degradedContent > 280 chars', function (): void {
    $tooLong = str_repeat('D', ArticleSummary::MAX_DEGRADED_CONTENT_LENGTH + 1);

    expect(static fn () => new ArticleSummary(
        articleId: 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        keyPoints: [],
        modelVersion: '',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        isDegraded: true,
        degradedContent: $tooLong,
    ))->toThrow(InvalidArgumentException::class);
});
