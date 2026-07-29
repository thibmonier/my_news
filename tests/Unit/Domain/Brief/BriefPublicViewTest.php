<?php

declare(strict_types=1);

use App\Domain\Brief\BriefPublicView;
use App\Domain\Brief\BriefStoryPublicView;
use App\Presentation\ViewModel\DailyBriefViewModel;

/*
 * Tests unitaires — BriefPublicView + DailyBriefViewModel (US-001).
 *
 * Valide :
 * - La construction des read models Domain (BriefPublicView + BriefStoryPublicView)
 * - La conversion en ViewModel (DailyBriefViewModel::fromPublicView)
 * - Le formatage de la date UTC (US-001 critère "DD MMM YYYY HH:MM UTC")
 * - Le formatage des positions (01/02/03 — INV-1)
 */

// ── BriefStoryPublicView ─────────────────────────────────────────────────────
test('BriefStoryPublicView expose toutes ses propriétés', function (): void {
    $story = new BriefStoryPublicView(
        position: 1,
        articleTitle: 'IA remplace les devs',
        articleUrl: 'https://example.com/ia-devs',
        excerpt: 'Un extrait de moins de 280 caractères.',
        sourceName: 'TechCrunch',
    );

    expect($story->position)->toBe(1)
        ->and($story->articleTitle)->toBe('IA remplace les devs')
        ->and($story->articleUrl)->toBe('https://example.com/ia-devs')
        ->and($story->excerpt)->toBe('Un extrait de moins de 280 caractères.')
        ->and($story->sourceName)->toBe('TechCrunch');
});

// ── BriefPublicView ──────────────────────────────────────────────────────────
test('BriefPublicView expose updatedAt et ses stories', function (): void {
    $updatedAt = new DateTimeImmutable('2026-07-28 09:00:00', new DateTimeZone('UTC'));
    $stories = [
        new BriefStoryPublicView(1, 'T1', 'https://ex.com/1', 'E1', 'S1'),
        new BriefStoryPublicView(2, 'T2', 'https://ex.com/2', 'E2', 'S2'),
        new BriefStoryPublicView(3, 'T3', 'https://ex.com/3', 'E3', 'S3'),
    ];

    $view = new BriefPublicView(updatedAt: $updatedAt, stories: $stories);

    expect($view->updatedAt)->toBe($updatedAt)
        ->and($view->stories)->toHaveCount(3);
});

// ── DailyBriefViewModel::fromPublicView ──────────────────────────────────────
test('DailyBriefViewModel::fromPublicView formate la date UTC correctement', function (): void {
    $updatedAt = new DateTimeImmutable('2026-07-28 14:30:00', new DateTimeZone('UTC'));
    $view = new BriefPublicView(
        updatedAt: $updatedAt,
        stories: [
            new BriefStoryPublicView(1, 'T1', 'https://ex.com/1', 'E1', 'S1'),
        ],
    );

    $vm = DailyBriefViewModel::fromPublicView($view);

    // Format attendu : "28 Jul 2026 14:30 UTC" (US-001 critère "DD MMM YYYY HH:MM UTC")
    expect($vm->lastUpdatedFormatted)->toBe('28 Jul 2026 14:30 UTC');
});

test('DailyBriefViewModel::fromPublicView convertit en UTC si timezone différente', function (): void {
    // Date en Europe/Paris (UTC+2) → doit être affichée en UTC
    $updatedAtParis = new DateTimeImmutable('2026-07-28 16:30:00', new DateTimeZone('Europe/Paris'));
    $view = new BriefPublicView(
        updatedAt: $updatedAtParis,
        stories: [
            new BriefStoryPublicView(1, 'T1', 'https://ex.com/1', 'E1', 'S1'),
        ],
    );

    $vm = DailyBriefViewModel::fromPublicView($view);

    // 16:30 Paris (UTC+2) = 14:30 UTC
    expect($vm->lastUpdatedFormatted)->toBe('28 Jul 2026 14:30 UTC');
});

test('DailyBriefViewModel::fromPublicView formate les positions en 01/02/03', function (): void {
    $view = new BriefPublicView(
        updatedAt: new DateTimeImmutable('now'),
        stories: [
            new BriefStoryPublicView(1, 'T1', 'https://ex.com/1', 'E1', 'S1'),
            new BriefStoryPublicView(2, 'T2', 'https://ex.com/2', 'E2', 'S2'),
            new BriefStoryPublicView(3, 'T3', 'https://ex.com/3', 'E3', 'S3'),
        ],
    );

    $vm = DailyBriefViewModel::fromPublicView($view);

    expect($vm->stories[0]->position)->toBe('01')
        ->and($vm->stories[1]->position)->toBe('02')
        ->and($vm->stories[2]->position)->toBe('03');
});

test('DailyBriefViewModel contient 3 stories avec toutes leurs propriétés', function (): void {
    $view = new BriefPublicView(
        updatedAt: new DateTimeImmutable('2026-07-28 09:00:00', new DateTimeZone('UTC')),
        stories: [
            new BriefStoryPublicView(1, 'GPT-5 lancé', 'https://openai.com/gpt5', 'OpenAI vient de lancer GPT-5.', 'OpenAI Blog'),
            new BriefStoryPublicView(2, 'Apple Vision Pro 2', 'https://apple.com/vision-pro-2', 'La prochaine génération arrive.', 'MacRumors'),
            new BriefStoryPublicView(3, 'Rust dans Linux 6.15', 'https://kernel.org/rust', 'Rust continue sa progression dans le noyau Linux.', 'Phoronix'),
        ],
    );

    $vm = DailyBriefViewModel::fromPublicView($view);

    expect($vm->stories)->toHaveCount(3)
        ->and($vm->stories[0]->title)->toBe('GPT-5 lancé')
        ->and($vm->stories[0]->sourceName)->toBe('OpenAI Blog')
        ->and($vm->stories[0]->sourceUrl)->toBe('https://openai.com/gpt5')
        ->and($vm->stories[0]->excerpt)->toBe('OpenAI vient de lancer GPT-5.');
});
