<?php

declare(strict_types=1);

use App\Domain\Synthesis\InvalidSynthesisLevelException;
use App\Domain\Synthesis\SynthesisLevel;

/*
 * Unit tests — SynthesisLevel enum (US-011 / T-011-10)
 *
 * Couvre :
 * - Les 3 cases (CONCISE, DETAILED, NARRATIVE) et leurs valeurs string
 * - promptInstructions() : non vide et distinctes pour les 3 cases
 * - timeoutSeconds() : 15 / 30 / 45
 * - fromString() : valeurs valides et exception sur valeur inconnue
 * - PII-free : aucun UUID ou e-mail dans les prompts
 */

// ── Cases et valeurs ──────────────────────────────────────────────────────────

test('SynthesisLevel::CONCISE a la valeur "concise"', function (): void {
    expect(SynthesisLevel::CONCISE->value)->toBe('concise');
});

test('SynthesisLevel::DETAILED a la valeur "detailed"', function (): void {
    expect(SynthesisLevel::DETAILED->value)->toBe('detailed');
});

test('SynthesisLevel::NARRATIVE a la valeur "narrative"', function (): void {
    expect(SynthesisLevel::NARRATIVE->value)->toBe('narrative');
});

// ── promptInstructions() ──────────────────────────────────────────────────────

test('promptInstructions() retourne une chaîne non vide pour CONCISE', function (): void {
    expect(SynthesisLevel::CONCISE->promptInstructions())->not->toBeEmpty();
});

test('promptInstructions() retourne une chaîne non vide pour DETAILED', function (): void {
    expect(SynthesisLevel::DETAILED->promptInstructions())->not->toBeEmpty();
});

test('promptInstructions() retourne une chaîne non vide pour NARRATIVE', function (): void {
    expect(SynthesisLevel::NARRATIVE->promptInstructions())->not->toBeEmpty();
});

test('les 3 prompts sont distincts', function (): void {
    $concise = SynthesisLevel::CONCISE->promptInstructions();
    $detailed = SynthesisLevel::DETAILED->promptInstructions();
    $narrative = SynthesisLevel::NARRATIVE->promptInstructions();

    expect($concise)->not->toBe($detailed);
    expect($concise)->not->toBe($narrative);
    expect($detailed)->not->toBe($narrative);
});

test('le prompt CONCISE contient les instructions de format 3 points', function (): void {
    $prompt = SynthesisLevel::CONCISE->promptInstructions();

    expect($prompt)->toContain('BRIEFLY AI:');
    expect($prompt)->toContain('180-220');
    expect($prompt)->toContain('3 key points');
});

test('le prompt DETAILED contient les instructions de format 5 points', function (): void {
    $prompt = SynthesisLevel::DETAILED->promptInstructions();

    expect($prompt)->toContain('BRIEFLY AI:');
    expect($prompt)->toContain('450-550');
    expect($prompt)->toContain('5 key points');
});

test('le prompt NARRATIVE contient les instructions prose éditoriale', function (): void {
    $prompt = SynthesisLevel::NARRATIVE->promptInstructions();

    expect($prompt)->toContain('BRIEFLY AI:');
    expect($prompt)->toContain('750-850');
    expect($prompt)->toContain('strong signal, low noise');
});

// ── PII-free ──────────────────────────────────────────────────────────────────

test('les prompts ne contiennent aucun UUID utilisateur (PII-free)', function (): void {
    $uuidPattern = '/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i';

    foreach (SynthesisLevel::cases() as $level) {
        expect($level->promptInstructions())->not->toMatch($uuidPattern);
    }
});

test('les prompts ne contiennent aucune adresse e-mail (PII-free)', function (): void {
    $emailPattern = '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/';

    foreach (SynthesisLevel::cases() as $level) {
        expect($level->promptInstructions())->not->toMatch($emailPattern);
    }
});

// ── timeoutSeconds() ─────────────────────────────────────────────────────────

test('CONCISE a un timeout de 15 secondes', function (): void {
    expect(SynthesisLevel::CONCISE->timeoutSeconds())->toBe(15);
});

test('DETAILED a un timeout de 30 secondes', function (): void {
    expect(SynthesisLevel::DETAILED->timeoutSeconds())->toBe(30);
});

test('NARRATIVE a un timeout de 45 secondes', function (): void {
    expect(SynthesisLevel::NARRATIVE->timeoutSeconds())->toBe(45);
});

test('les timeouts sont croissants CONCISE < DETAILED < NARRATIVE', function (): void {
    expect(SynthesisLevel::CONCISE->timeoutSeconds())
        ->toBeLessThan(SynthesisLevel::DETAILED->timeoutSeconds());

    expect(SynthesisLevel::DETAILED->timeoutSeconds())
        ->toBeLessThan(SynthesisLevel::NARRATIVE->timeoutSeconds());
});

// ── fromString() ─────────────────────────────────────────────────────────────

test('fromString("concise") retourne SynthesisLevel::CONCISE', function (): void {
    expect(SynthesisLevel::fromString('concise'))->toBe(SynthesisLevel::CONCISE);
});

test('fromString("detailed") retourne SynthesisLevel::DETAILED', function (): void {
    expect(SynthesisLevel::fromString('detailed'))->toBe(SynthesisLevel::DETAILED);
});

test('fromString("narrative") retourne SynthesisLevel::NARRATIVE', function (): void {
    expect(SynthesisLevel::fromString('narrative'))->toBe(SynthesisLevel::NARRATIVE);
});

test('fromString("ultra") lève InvalidSynthesisLevelException', function (): void {
    expect(static fn () => SynthesisLevel::fromString('ultra'))
        ->toThrow(InvalidSynthesisLevelException::class);
});

test('fromString("") lève InvalidSynthesisLevelException', function (): void {
    expect(static fn () => SynthesisLevel::fromString(''))
        ->toThrow(InvalidSynthesisLevelException::class);
});

test('fromString("CONCISE") lève InvalidSynthesisLevelException (casse stricte)', function (): void {
    // L'enum attend des valeurs en minuscules
    expect(static fn () => SynthesisLevel::fromString('CONCISE'))
        ->toThrow(InvalidSynthesisLevelException::class);
});

test('InvalidSynthesisLevelException contient le message attendu (US-011 Gherkin erreur 1)', function (): void {
    try {
        SynthesisLevel::fromString('ultra');
        $this->fail('Exception attendue');
    } catch (InvalidSynthesisLevelException $e) {
        expect($e->getMessage())->toContain('level must be one of:');
        expect($e->getMessage())->toContain('concise');
        expect($e->getMessage())->toContain('detailed');
        expect($e->getMessage())->toContain('narrative');
    }
});
