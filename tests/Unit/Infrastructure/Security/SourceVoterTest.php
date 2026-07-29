<?php

declare(strict_types=1);

use App\Domain\Feed\FeedType;
use App\Domain\Feed\Source;
use App\Domain\Feed\SourcePermission;
use App\Domain\Feed\SourceStatus;
use App\Infrastructure\User\Security\SourceVoter;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/*
 * Tests unitaires — SourceVoter
 *
 * Vérifie que ROLE_ADMIN est requis pour toutes les opérations admin sur les Sources.
 * Un utilisateur non ROLE_ADMIN ou non authentifié doit obtenir ACCESS_DENIED.
 *
 * Couverture :
 * - ROLE_ADMIN → accès accordé pour CREATE, EDIT, DELETE, TOGGLE, BULK
 * - ROLE_USER  → accès refusé
 * - Non authentifié (NullToken) → accès refusé
 */

uses(PHPUnit\Framework\TestCase::class);

function makeSource(): Source
{
    return new Source(
        id: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
        name: 'Test Source',
        url: 'https://test.com/feed',
        feedType: FeedType::Rss,
        status: SourceStatus::Active,
    );
}

function makeAdminToken(): UsernamePasswordToken
{
    $user = new InMemoryUser('admin', null, ['ROLE_ADMIN']);

    return new UsernamePasswordToken($user, 'admin', $user->getRoles());
}

function makeUserToken(): UsernamePasswordToken
{
    $user = new InMemoryUser('user', null, ['ROLE_USER']);

    return new UsernamePasswordToken($user, 'main', $user->getRoles());
}

// ── ROLE_ADMIN → accès accordé ────────────────────────────────────────────

test('SourceVoter : ROLE_ADMIN peut créer une source', function (): void {
    $voter = new SourceVoter();
    $result = $voter->vote(makeAdminToken(), null, [SourcePermission::CREATE]);

    expect($result)->toBe(1); // ACCESS_GRANTED
});

test('SourceVoter : ROLE_ADMIN peut éditer une source', function (): void {
    $voter = new SourceVoter();
    $result = $voter->vote(makeAdminToken(), makeSource(), [SourcePermission::EDIT]);

    expect($result)->toBe(1);
});

test('SourceVoter : ROLE_ADMIN peut supprimer une source', function (): void {
    $voter = new SourceVoter();
    $result = $voter->vote(makeAdminToken(), makeSource(), [SourcePermission::DELETE]);

    expect($result)->toBe(1);
});

test('SourceVoter : ROLE_ADMIN peut toggler une source', function (): void {
    $voter = new SourceVoter();
    $result = $voter->vote(makeAdminToken(), makeSource(), [SourcePermission::TOGGLE]);

    expect($result)->toBe(1);
});

test('SourceVoter : ROLE_ADMIN peut déclencher un bulk update', function (): void {
    $voter = new SourceVoter();
    $result = $voter->vote(makeAdminToken(), null, [SourcePermission::BULK]);

    expect($result)->toBe(1);
});

// ── ROLE_USER → accès refusé ──────────────────────────────────────────────

test('SourceVoter : ROLE_USER ne peut pas créer une source', function (): void {
    $voter = new SourceVoter();
    $result = $voter->vote(makeUserToken(), null, [SourcePermission::CREATE]);

    expect($result)->toBe(-1); // ACCESS_DENIED
});

test('SourceVoter : ROLE_USER ne peut pas éditer une source', function (): void {
    $voter = new SourceVoter();
    $result = $voter->vote(makeUserToken(), makeSource(), [SourcePermission::EDIT]);

    expect($result)->toBe(-1);
});

test('SourceVoter : ROLE_USER ne peut pas supprimer une source', function (): void {
    $voter = new SourceVoter();
    $result = $voter->vote(makeUserToken(), makeSource(), [SourcePermission::DELETE]);

    expect($result)->toBe(-1);
});

// ── Non authentifié → accès refusé ───────────────────────────────────────

test('SourceVoter : NullToken refuse l\'accès CREATE', function (): void {
    $voter = new SourceVoter();
    $result = $voter->vote(new NullToken(), null, [SourcePermission::CREATE]);

    expect($result)->toBe(-1);
});

test('SourceVoter : NullToken refuse l\'accès EDIT', function (): void {
    $voter = new SourceVoter();
    $result = $voter->vote(new NullToken(), makeSource(), [SourcePermission::EDIT]);

    expect($result)->toBe(-1);
});

// ── Attribut non géré → abstention ───────────────────────────────────────

test('SourceVoter : attribut inconnu → abstention', function (): void {
    $voter = new SourceVoter();
    $result = $voter->vote(makeAdminToken(), null, ['ARTICLE_CREATE']);

    expect($result)->toBe(0); // ACCESS_ABSTAIN
});
