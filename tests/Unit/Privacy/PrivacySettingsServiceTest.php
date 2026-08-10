<?php

declare(strict_types=1);

use App\Application\Privacy\PrivacySettingsService;
use App\Domain\Privacy\PrivacySettingsRepositoryInterface;
use App\Domain\Privacy\UserPrivacySettings;

/*
 * Tests unitaires — PrivacySettingsService (US-035 T-035-11).
 *
 * Vérifie :
 *   - getSettings() pour userId sans entrée → retourne défaut {true, true, true}
 *   - updateSettings() analyticsOptIn=false → repository save() appelé avec les bonnes valeurs + updatedAt UTC
 *   - exportAsJson() → array {analytics_opt_in, personalized_recs, search_engine_indexing, updated_at} ISO8601
 *   - 0 données d'autres utilisateurs dans l'export
 */

uses(PHPUnit\Framework\TestCase::class);

// ── Stub repository ───────────────────────────────────────────────────────────

function makeRepoWithDefault(): PrivacySettingsRepositoryInterface
{
    return new class implements PrivacySettingsRepositoryInterface {
        /** @var array<string, UserPrivacySettings> */
        public array $store = [];

        public function findByUserId(string $userId): UserPrivacySettings
        {
            return $this->store[$userId] ?? UserPrivacySettings::defaults();
        }

        public function save(string $userId, UserPrivacySettings $settings): void
        {
            $this->store[$userId] = $settings;
        }
    };
}

// ── getSettings() → valeurs par défaut si absent ─────────────────────────────

test('PrivacySettingsService::getSettings() sans entrée → valeurs par défaut opt-in', function (): void {
    $repo = makeRepoWithDefault();
    $service = new PrivacySettingsService($repo);

    $settings = $service->getSettings('user-uuid-123');

    expect($settings->analyticsOptIn)->toBeTrue()
        ->and($settings->personalizedRecs)->toBeTrue()
        ->and($settings->searchEngineIndexing)->toBeTrue();
});

// ── updateSettings() → save() avec les bonnes valeurs ────────────────────────

test('PrivacySettingsService::updateSettings() analyticsOptIn=false → repository save() avec bonnes valeurs', function (): void {
    $repo = makeRepoWithDefault();
    $service = new PrivacySettingsService($repo);

    $userId = 'user-uuid-456';
    $before = new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC'));

    $service->updateSettings(
        userId: $userId,
        analyticsOptIn: false,
        personalizedRecs: true,
        searchEngineIndexing: true,
    );

    expect($repo->store)->toHaveKey($userId);

    $saved = $repo->store[$userId];
    expect($saved->analyticsOptIn)->toBeFalse()
        ->and($saved->personalizedRecs)->toBeTrue()
        ->and($saved->searchEngineIndexing)->toBeTrue();

    // updatedAt doit être récent (après $before)
    expect($saved->updatedAt)->toBeGreaterThan($before);
    expect($saved->updatedAt->getTimezone()->getName())->toBe('UTC');
});

test('PrivacySettingsService::updateSettings() persiste l\'horodatage updatedAt UTC courant', function (): void {
    $repo = makeRepoWithDefault();
    $service = new PrivacySettingsService($repo);

    $userId = 'user-uuid-789';
    $callTime = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $service->updateSettings($userId, false, false, false);

    $saved = $repo->store[$userId];
    // updatedAt doit être très proche de l'instant d'appel (dans les 5 secondes)
    $diff = abs($saved->updatedAt->getTimestamp() - $callTime->getTimestamp());
    expect($diff)->toBeLessThan(5);
});

// ── exportAsJson() ────────────────────────────────────────────────────────────

test('PrivacySettingsService::exportAsJson() → array correct + ISO8601', function (): void {
    $repo = makeRepoWithDefault();
    $service = new PrivacySettingsService($repo);

    $userId = 'user-uuid-export';
    $service->updateSettings($userId, false, true, false);

    $export = $service->exportAsJson($userId);

    expect($export)->toBeArray()
        ->and($export)->toHaveKeys(['analytics_opt_in', 'personalized_recs', 'search_engine_indexing', 'updated_at'])
        ->and($export['analytics_opt_in'])->toBeFalse()
        ->and($export['personalized_recs'])->toBeTrue()
        ->and($export['search_engine_indexing'])->toBeFalse();

    // updated_at doit être une date ISO8601 valide
    expect($export['updated_at'])->toBeString();
    $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $export['updated_at']);
    expect($parsed)->not->toBeFalse();
});

test('PrivacySettingsService::exportAsJson() → 0 données d\'autres utilisateurs', function (): void {
    $repo = makeRepoWithDefault();
    $service = new PrivacySettingsService($repo);

    // Deux utilisateurs avec des valeurs différentes
    $service->updateSettings('marc-uuid', false, false, false);
    $service->updateSettings('thomas-uuid', true, true, true);

    $exportMarc = $service->exportAsJson('marc-uuid');
    $exportThomas = $service->exportAsJson('thomas-uuid');

    // Export de Marc ne contient pas les données de Thomas
    expect($exportMarc['analytics_opt_in'])->toBeFalse();
    expect($exportThomas['analytics_opt_in'])->toBeTrue();

    // Aucune "fuite croisée"
    expect($exportMarc)->not->toEqual($exportThomas);
});
