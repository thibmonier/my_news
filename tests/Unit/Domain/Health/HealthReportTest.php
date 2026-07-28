<?php

declare(strict_types=1);

use App\Domain\Health\ComponentStatus;
use App\Domain\Health\HealthReport;

/*
 * Unit tests — HealthReport (Value Object domaine)
 *
 * Tests purement unitaires : aucune dépendance framework, aucun I/O.
 * Couvre : agrégation du statut, immutabilité, accès aux composants.
 */

test('HealthReport retourne ok quand tous les composants sont sains', static function (): void {
    $report = new HealthReport([
        new ComponentStatus('database', 'ok', 'Connected'),
        new ComponentStatus('redis', 'ok', 'Connected'),
    ]);

    expect($report->isHealthy())->toBeTrue()
        ->and($report->getStatus())->toBe('ok');
});

test('HealthReport retourne degraded si au moins un composant est dégradé', static function (): void {
    $report = new HealthReport([
        new ComponentStatus('database', 'ok', 'Connected'),
        new ComponentStatus('redis', 'degraded', 'Connection refused'),
    ]);

    expect($report->isHealthy())->toBeFalse()
        ->and($report->getStatus())->toBe('degraded');
});

test('HealthReport est degraded si tous les composants sont dégradés', static function (): void {
    $report = new HealthReport([
        new ComponentStatus('database', 'degraded', 'Timeout'),
        new ComponentStatus('redis', 'degraded', 'Timeout'),
    ]);

    expect($report->isHealthy())->toBeFalse()
        ->and($report->getStatus())->toBe('degraded');
});

test('HealthReport est ok avec une liste vide de composants', static function (): void {
    $report = new HealthReport([]);

    expect($report->isHealthy())->toBeTrue()
        ->and($report->getStatus())->toBe('ok');
});

test('HealthReport expose les composants fournis à la construction', static function (): void {
    $db = new ComponentStatus('database', 'ok', 'Connected');
    $redis = new ComponentStatus('redis', 'ok', 'Connected');

    $report = new HealthReport([$db, $redis]);

    expect($report->getComponents())->toHaveCount(2)
        ->and($report->getComponents()[0]->name)->toBe('database')
        ->and($report->getComponents()[1]->name)->toBe('redis');
});

test('HealthReport est immuable — modifier la liste source ne change pas le rapport', static function (): void {
    $components = [new ComponentStatus('database', 'ok', 'Connected')];
    $report = new HealthReport($components);

    // Tenter d'altérer depuis l'extérieur n'a aucun effet
    $components[] = new ComponentStatus('redis', 'degraded', 'Error');

    expect($report->getComponents())->toHaveCount(1)
        ->and($report->isHealthy())->toBeTrue();
});

test('ComponentStatus isHealthy retourne true pour status ok', static function (): void {
    $status = new ComponentStatus('database', 'ok', 'Connected');

    expect($status->isHealthy())->toBeTrue();
});

test('ComponentStatus isHealthy retourne false pour status degraded', static function (): void {
    $status = new ComponentStatus('redis', 'degraded', 'Connection refused');

    expect($status->isHealthy())->toBeFalse();
});
