<?php

declare(strict_types=1);

use App\Domain\User\IdentifiableUserInterface;
use App\Presentation\Security\PrivacySettingsVoter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/*
 * Tests unitaires — PrivacySettingsVoter (US-035 T-035-10).
 *
 * Vérifie :
 *   - Propriétaire (même UUID) → ACCESS_GRANTED
 *   - UUID différent            → ACCESS_DENIED + log WARNING UUID (0 email)
 *   - Non authentifié (NullToken) → ACCESS_DENIED
 *   - InMemoryUser sans IdentifiableUserInterface → ACCESS_DENIED
 *   - Attribut inconnu → ACCESS_ABSTAIN
 *
 * RGPD : log WARNING contient uniquement des UUIDs (jamais d'email).
 */

uses(PHPUnit\Framework\TestCase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makePrivacySubject(string $uuid): IdentifiableUserInterface
{
    return new class($uuid) implements IdentifiableUserInterface {
        public function __construct(private readonly string $uuid)
        {
        }

        public function getUserUuid(): string
        {
            return $this->uuid;
        }
    };
}

function makePrivacyUser(string $uuid): IdentifiableUserInterface
{
    return new class($uuid) implements IdentifiableUserInterface, Symfony\Component\Security\Core\User\UserInterface {
        public function __construct(private readonly string $uuid)
        {
        }

        public function getUserUuid(): string
        {
            return $this->uuid;
        }

        public function getUserIdentifier(): string
        {
            return 'user@example.com';
        }

        /** @return list<string> */
        public function getRoles(): array
        {
            return ['ROLE_USER'];
        }

        public function eraseCredentials(): void
        {
        }
    };
}

/** @return array{voter: PrivacySettingsVoter, logger: LoggerInterface&object{logs: list<array{level:string, message:string, context:array<string,mixed>}>}} */
function makePrivacyVoter(): array
{
    $logger = new class implements LoggerInterface {
        /** @var list<array{level:string, message:string, context:array<string,mixed>}> */
        public array $logs = [];

        public function emergency(string|Stringable $message, array $context = []): void
        {
            $this->log('emergency', $message, $context);
        }

        public function alert(string|Stringable $message, array $context = []): void
        {
            $this->log('alert', $message, $context);
        }

        public function critical(string|Stringable $message, array $context = []): void
        {
            $this->log('critical', $message, $context);
        }

        public function error(string|Stringable $message, array $context = []): void
        {
            $this->log('error', $message, $context);
        }

        public function warning(string|Stringable $message, array $context = []): void
        {
            $this->log('warning', $message, $context);
        }

        public function notice(string|Stringable $message, array $context = []): void
        {
            $this->log('notice', $message, $context);
        }

        public function info(string|Stringable $message, array $context = []): void
        {
            $this->log('info', $message, $context);
        }

        public function debug(string|Stringable $message, array $context = []): void
        {
            $this->log('debug', $message, $context);
        }

        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
        }
    };

    return ['voter' => new PrivacySettingsVoter($logger), 'logger' => $logger];
}

// ── Propriétaire → GRANT ──────────────────────────────────────────────────────

test('PrivacySettingsVoter : propriétaire (même UUID) → ACCESS_GRANTED', function (): void {
    $uuid = '123e4567-e89b-12d3-a456-426614174000';
    ['voter' => $voter] = makePrivacyVoter();

    $user = makePrivacyUser($uuid);
    $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
    $subject = makePrivacySubject($uuid);

    $result = $voter->vote($token, $subject, [PrivacySettingsVoter::EDIT]);

    expect($result)->toBe(1); // ACCESS_GRANTED
});

// ── UUID différent → DENY + log WARNING ──────────────────────────────────────

test('PrivacySettingsVoter : UUID différent → ACCESS_DENIED', function (): void {
    ['voter' => $voter, 'logger' => $logger] = makePrivacyVoter();

    $requester = makePrivacyUser('aaa-111');
    $target = makePrivacySubject('bbb-222');
    $token = new UsernamePasswordToken($requester, 'main', $requester->getRoles());

    $result = $voter->vote($token, $target, [PrivacySettingsVoter::EDIT]);

    expect($result)->toBe(-1) // ACCESS_DENIED
        ->and($logger->logs)->not->toBeEmpty()
        ->and($logger->logs[0]['level'])->toBe('warning')
        ->and($logger->logs[0]['message'])->toBe('privacy.unauthorized_edit_attempt');
});

test('PrivacySettingsVoter : log WARNING contient UUID demandeur et cible (0 email — RGPD)', function (): void {
    ['voter' => $voter, 'logger' => $logger] = makePrivacyVoter();

    $requester = makePrivacyUser('aaa-111');
    $target = makePrivacySubject('bbb-222');
    $token = new UsernamePasswordToken($requester, 'main', $requester->getRoles());

    $voter->vote($token, $target, [PrivacySettingsVoter::EDIT]);

    $log = $logger->logs[0] ?? null;
    expect($log)->not->toBeNull();

    $contextJson = json_encode($log['context'] ?? []);

    // UUIDs présents dans le contexte
    expect($contextJson)->toContain('aaa-111')
        ->and($contextJson)->toContain('bbb-222');

    // RGPD : aucun email dans le log
    expect($contextJson)->not->toContain('@')
        ->and($contextJson)->not->toContain('email');
});

// ── Non authentifié → DENY ───────────────────────────────────────────────────

test('PrivacySettingsVoter : NullToken → ACCESS_DENIED', function (): void {
    ['voter' => $voter] = makePrivacyVoter();
    $subject = makePrivacySubject('some-uuid');

    $result = $voter->vote(new NullToken(), $subject, [PrivacySettingsVoter::EDIT]);

    expect($result)->toBe(-1); // ACCESS_DENIED
});

// ── InMemoryUser sans IdentifiableUserInterface → DENY ───────────────────────

test('PrivacySettingsVoter : InMemoryUser (sans IdentifiableUserInterface) → ACCESS_DENIED', function (): void {
    ['voter' => $voter] = makePrivacyVoter();
    $subject = makePrivacySubject('some-uuid');

    $user = new InMemoryUser('user@example.com', null, ['ROLE_USER']);
    $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

    $result = $voter->vote($token, $subject, [PrivacySettingsVoter::EDIT]);

    expect($result)->toBe(-1); // ACCESS_DENIED
});

// ── Attribut inconnu → ABSTAIN ────────────────────────────────────────────────

test('PrivacySettingsVoter : attribut inconnu → ACCESS_ABSTAIN', function (): void {
    ['voter' => $voter] = makePrivacyVoter();
    $subject = makePrivacySubject('some-uuid');

    $user = makePrivacyUser('some-uuid');
    $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

    $result = $voter->vote($token, $subject, ['PRIVACY_SETTINGS_DELETE']);

    expect($result)->toBe(0); // ACCESS_ABSTAIN
});
