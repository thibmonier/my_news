<?php

declare(strict_types=1);

use App\Domain\User\IdentifiableUserInterface;
use App\Domain\User\UserProfileInterface;
use App\Presentation\Security\ProfileVoter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/*
 * Tests unitaires — ProfileVoter (US-032)
 *
 * Vérifie la logique d'autorisation du voter :
 *   - Propriétaire (même UUID) → GRANT
 *   - UUID différent            → DENY + log WARN
 *   - Non authentifié           → DENY
 *   - User sans IdentifiableUserInterface (InMemoryUser) → DENY
 *   - Attribut inconnu          → ABSTAIN
 *
 * RGPD : le voter ne log jamais l'email — UUIDs uniquement.
 */

uses(PHPUnit\Framework\TestCase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Crée un UserProfileInterface mock avec un UUID donné.
 */
function makeProfileSubject(string $uuid): UserProfileInterface
{
    return new class($uuid) implements UserProfileInterface {
        public function __construct(private string $uuid)
        {
        }

        public function getUserUuid(): string
        {
            return $this->uuid;
        }

        public function getEmail(): string
        {
            return 'user@example.com';
        }

        public function getFullName(): string
        {
            return 'Test User';
        }

        public function getBio(): ?string
        {
            return null;
        }

        public function getEmailPending(): ?string
        {
            return null;
        }
    };
}

/**
 * Crée un IdentifiableUserInterface + UserInterface mock avec un UUID donné.
 */
function makeIdentifiableUser(string $uuid): IdentifiableUserInterface
{
    return new class($uuid) implements IdentifiableUserInterface, Symfony\Component\Security\Core\User\UserInterface {
        public function __construct(private string $uuid)
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

function makeVoter(): ProfileVoter
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

    return new ProfileVoter($logger);
}

// ── Propriétaire → GRANT ──────────────────────────────────────────────────────

test('ProfileVoter : propriétaire (même UUID) → ACCESS_GRANTED', function (): void {
    $uuid = '123e4567-e89b-12d3-a456-426614174000';
    $voter = makeVoter();

    $user = makeIdentifiableUser($uuid);
    $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
    $subject = makeProfileSubject($uuid);

    $result = $voter->vote($token, $subject, [ProfileVoter::EDIT]);

    expect($result)->toBe(1); // ACCESS_GRANTED
});

// ── UUID différent → DENY + log WARN ─────────────────────────────────────────

test('ProfileVoter : UUID différent → ACCESS_DENIED', function (): void {
    $logger = new class implements LoggerInterface {
        /** @var list<array{level:string, message:string}> */
        public array $logs = [];

        public function emergency(string|Stringable $message, array $context = []): void
        {
            $this->logs[] = ['level' => 'emergency', 'message' => (string) $message];
        }

        public function alert(string|Stringable $message, array $context = []): void
        {
            $this->logs[] = ['level' => 'alert', 'message' => (string) $message];
        }

        public function critical(string|Stringable $message, array $context = []): void
        {
            $this->logs[] = ['level' => 'critical', 'message' => (string) $message];
        }

        public function error(string|Stringable $message, array $context = []): void
        {
            $this->logs[] = ['level' => 'error', 'message' => (string) $message];
        }

        public function warning(string|Stringable $message, array $context = []): void
        {
            $this->logs[] = ['level' => 'warning', 'message' => (string) $message];
        }

        public function notice(string|Stringable $message, array $context = []): void
        {
            $this->logs[] = ['level' => 'notice', 'message' => (string) $message];
        }

        public function info(string|Stringable $message, array $context = []): void
        {
            $this->logs[] = ['level' => 'info', 'message' => (string) $message];
        }

        public function debug(string|Stringable $message, array $context = []): void
        {
            $this->logs[] = ['level' => 'debug', 'message' => (string) $message];
        }

        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->logs[] = ['level' => (string) $level, 'message' => (string) $message];
        }
    };

    $voter = new ProfileVoter($logger);

    $requester = makeIdentifiableUser('aaa-111');
    $target = makeProfileSubject('bbb-222');
    $token = new UsernamePasswordToken($requester, 'main', $requester->getRoles());

    $result = $voter->vote($token, $target, [ProfileVoter::EDIT]);

    expect($result)->toBe(-1) // ACCESS_DENIED
        ->and($logger->logs)->not->toBeEmpty()
        ->and($logger->logs[0]['level'])->toBe('warning')
        ->and($logger->logs[0]['message'])->toBe('profile.unauthorized_edit_attempt');
});

test('ProfileVoter : log WARN ne contient pas d\'email (RGPD)', function (): void {
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

    $voter = new ProfileVoter($logger);
    $requester = makeIdentifiableUser('aaa-111');
    $target = makeProfileSubject('bbb-222');
    $token = new UsernamePasswordToken($requester, 'main', $requester->getRoles());

    $voter->vote($token, $target, [ProfileVoter::EDIT]);

    $warningLog = $logger->logs[0] ?? null;
    expect($warningLog)->not->toBeNull();

    // RGPD : les contextes de log ne doivent pas contenir d'email
    $contextJson = json_encode($warningLog['context'] ?? []);
    expect($contextJson)->not->toContain('@')
        ->and($contextJson)->not->toContain('email');
});

// ── Non authentifié → DENY ────────────────────────────────────────────────────

test('ProfileVoter : NullToken → ACCESS_DENIED', function (): void {
    $voter = makeVoter();
    $subject = makeProfileSubject('some-uuid');

    $result = $voter->vote(new NullToken(), $subject, [ProfileVoter::EDIT]);

    expect($result)->toBe(-1); // ACCESS_DENIED
});

// ── InMemoryUser sans IdentifiableUserInterface → DENY ────────────────────────

test('ProfileVoter : InMemoryUser (sans IdentifiableUserInterface) → ACCESS_DENIED', function (): void {
    $voter = makeVoter();
    $subject = makeProfileSubject('some-uuid');

    $user = new InMemoryUser('user@example.com', null, ['ROLE_USER']);
    $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

    $result = $voter->vote($token, $subject, [ProfileVoter::EDIT]);

    expect($result)->toBe(-1); // ACCESS_DENIED
});

// ── Attribut inconnu → ABSTAIN ────────────────────────────────────────────────

test('ProfileVoter : attribut inconnu → ACCESS_ABSTAIN', function (): void {
    $voter = makeVoter();
    $subject = makeProfileSubject('some-uuid');

    $user = makeIdentifiableUser('some-uuid');
    $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

    $result = $voter->vote($token, $subject, ['PROFILE_DELETE']); // attribut non géré

    expect($result)->toBe(0); // ACCESS_ABSTAIN
});
