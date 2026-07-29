<?php

declare(strict_types=1);

use App\Domain\Feed\ArticleCategory;
use App\Domain\Feed\InvalidCategoryException;

/*
 * Tests unitaires — ArticleCategory (US-005/T-005-08).
 *
 * Valide :
 * - Les 5 cases de l'enum (valeurs backing string)
 * - label() : libellé affiché dans le badge (WCAG 2.1 AA — pas couleur seule)
 * - badgeColor() : nom du token CSS de couleur (JAMAIS émeraude — INV-2)
 * - fromDatabaseValue() : conversion depuis la base de données
 * - InvalidCategoryException levée sur valeur inconnue 'BREAKING_NEWS'
 *
 * Gherkin couvert : US-005 erreur 1 (catégorie invalide → exception).
 */

// ── Cases de l'enum ───────────────────────────────────────────────────────────

test('ArticleCategory::AiInsight a la valeur backing "ai_insight"', function (): void {
    expect(ArticleCategory::AiInsight->value)->toBe('ai_insight');
});

test('ArticleCategory::Geopolitics a la valeur backing "geopolitics"', function (): void {
    expect(ArticleCategory::Geopolitics->value)->toBe('geopolitics');
});

test('ArticleCategory::Productivity a la valeur backing "productivity"', function (): void {
    expect(ArticleCategory::Productivity->value)->toBe('productivity');
});

test('ArticleCategory::Research a la valeur backing "research"', function (): void {
    expect(ArticleCategory::Research->value)->toBe('research');
});

test('ArticleCategory::Sustainability a la valeur backing "sustainability"', function (): void {
    expect(ArticleCategory::Sustainability->value)->toBe('sustainability');
});

// ── label() — libellés affichés (WCAG 2.1 AA) ────────────────────────────────

test('ArticleCategory::AiInsight label() retourne "AI INSIGHT"', function (): void {
    expect(ArticleCategory::AiInsight->label())->toBe('AI INSIGHT');
});

test('ArticleCategory::Geopolitics label() retourne "GEOPOLITICS"', function (): void {
    expect(ArticleCategory::Geopolitics->label())->toBe('GEOPOLITICS');
});

test('ArticleCategory::Productivity label() retourne "PRODUCTIVITY"', function (): void {
    expect(ArticleCategory::Productivity->label())->toBe('PRODUCTIVITY');
});

test('ArticleCategory::Research label() retourne "RESEARCH"', function (): void {
    expect(ArticleCategory::Research->label())->toBe('RESEARCH');
});

test('ArticleCategory::Sustainability label() retourne "SUSTAINABILITY"', function (): void {
    expect(ArticleCategory::Sustainability->label())->toBe('SUSTAINABILITY');
});

// ── badgeColor() — tokens CSS (JAMAIS émeraude — INV-2) ──────────────────────

test('aucun badgeColor() ne retourne la couleur émeraude (INV-2)', function (): void {
    foreach (ArticleCategory::cases() as $category) {
        expect($category->badgeColor())->not->toContain('10B981')
            ->and($category->badgeColor())->not->toBe('emerald');
    }
});

test('ArticleCategory::AiInsight badgeColor() retourne "violet"', function (): void {
    expect(ArticleCategory::AiInsight->badgeColor())->toBe('violet');
});

test('ArticleCategory::Geopolitics badgeColor() retourne "red"', function (): void {
    expect(ArticleCategory::Geopolitics->badgeColor())->toBe('red');
});

test('ArticleCategory::Productivity badgeColor() retourne "blue"', function (): void {
    expect(ArticleCategory::Productivity->badgeColor())->toBe('blue');
});

test('ArticleCategory::Research badgeColor() retourne "orange"', function (): void {
    expect(ArticleCategory::Research->badgeColor())->toBe('orange');
});

test('ArticleCategory::Sustainability badgeColor() retourne "green-dark"', function (): void {
    expect(ArticleCategory::Sustainability->badgeColor())->toBe('green-dark');
});

// ── fromDatabaseValue() — conversion depuis DB ───────────────────────────────

test('fromDatabaseValue("ai_insight", ...) retourne AiInsight', function (): void {
    $category = ArticleCategory::fromDatabaseValue('ai_insight', 'test-uuid');
    expect($category)->toBe(ArticleCategory::AiInsight);
});

test('fromDatabaseValue("productivity", ...) retourne Productivity', function (): void {
    $category = ArticleCategory::fromDatabaseValue('productivity', 'test-uuid');
    expect($category)->toBe(ArticleCategory::Productivity);
});

test('fromDatabaseValue("sustainability", ...) retourne Sustainability', function (): void {
    $category = ArticleCategory::fromDatabaseValue('sustainability', 'test-uuid');
    expect($category)->toBe(ArticleCategory::Sustainability);
});

// ── InvalidCategoryException — valeur invalide ─────────────────────────────

test('fromDatabaseValue("BREAKING_NEWS", ...) lève InvalidCategoryException (US-005/erreur 1)', function (): void {
    $articleId = 'aabbccdd-1234-5678-abcd-ef0123456789';

    expect(
        static fn () => ArticleCategory::fromDatabaseValue('BREAKING_NEWS', $articleId),
    )->toThrow(InvalidCategoryException::class);
});

test('InvalidCategoryException contient l\'article_id et la valeur invalide', function (): void {
    $articleId = 'aabbccdd-1234-5678-abcd-ef0123456789';
    $invalidValue = 'BREAKING_NEWS';

    try {
        ArticleCategory::fromDatabaseValue($invalidValue, $articleId);
        $this->fail('InvalidCategoryException attendue');
    } catch (InvalidCategoryException $e) {
        expect($e->articleId)->toBe($articleId)
            ->and($e->invalidValue)->toBe($invalidValue)
            ->and($e->getMessage())->toContain($invalidValue)
            ->and($e->getMessage())->toContain($articleId);
    }
});

test('InvalidCategoryException est une RuntimeException', function (): void {
    try {
        ArticleCategory::fromDatabaseValue('UNKNOWN', 'test-id');
    } catch (RuntimeException $e) {
        expect($e)->toBeInstanceOf(InvalidCategoryException::class);
    }
});

// ── Exhaustivité — les 5 cases sont toutes les cases ─────────────────────────

test('ArticleCategory possède exactement 5 cases', function (): void {
    expect(ArticleCategory::cases())->toHaveCount(5);
});
