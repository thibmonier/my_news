<?php

declare(strict_types=1);

use App\Application\User\Profile\EmailAlreadyInUseException;
use App\Application\User\Profile\EmailChangeService;
use App\Application\User\Profile\EmailNotificationInterface;
use App\Domain\User\Email;
use App\Domain\User\User;
use App\Domain\User\UserRepositoryInterface;
use Psr\Log\NullLogger;

/*
 * Tests unitaires — EmailChangeService (US-032)
 *
 * Couvre :
 *   - Génération token UUID v4 valide
 *   - email_pending stocké, email courant inchangé
 *   - email_pending_expires_at dans +24h
 *   - Email de confirmation envoyé (spy EmailNotificationInterface)
 *   - Rejet si email déjà utilisé par un autre compte
 *   - confirmChange() valide → email mis à jour + champs vidés
 *   - confirmChange() token expiré → retourne false
 *   - confirmChange() token inconnu → retourne false
 *   - Email identique à l'email courant → rien à faire
 */

uses(PHPUnit\Framework\TestCase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeProfileUser(string $id, string $email): User
{
    return new User(
        id: $id,
        email: new Email($email),
        passwordHash: 'hash',
        fullName: 'Test User',
        createdAt: new DateTimeImmutable('2024-01-01'),
        consentAt: new DateTimeImmutable('2024-01-01'),
    );
}

/**
 * Crée un UserRepositoryInterface mock qui connaît un seul utilisateur.
 *
 * @param array<string, User> $usersById UUID → User
 * @param array<string, User> $usersByEmail email → User
 */
function makeRepo(array $usersById = [], array $usersByEmail = []): UserRepositoryInterface
{
    return new class($usersById, $usersByEmail) implements UserRepositoryInterface {
        /**
         * @param array<string, User> $usersById
         * @param array<string, User> $usersByEmail
         */
        public function __construct(
            private array $usersById,
            private array $usersByEmail,
        ) {
        }

        /** @var list<User> */
        public array $saved = [];

        public function save(User $user): void
        {
            $this->saved[] = $user;
        }

        public function findByEmail(Email $email): ?User
        {
            return $this->usersByEmail[$email->getValue()] ?? null;
        }

        public function emailExists(Email $email): bool
        {
            return isset($this->usersByEmail[$email->getValue()]);
        }

        public function findById(string $id): ?User
        {
            return $this->usersById[$id] ?? null;
        }

        public function findByEmailPendingToken(string $token): ?User
        {
            foreach ($this->usersById as $user) {
                if ($user->getEmailPendingToken() === $token) {
                    return $user;
                }
            }

            return null;
        }
    };
}

/**
 * Crée un spy pour EmailNotificationInterface.
 */
function makeEmailSpy(): EmailNotificationInterface
{
    return new class implements EmailNotificationInterface {
        /** @var list<array{to:string, url:string}> */
        public array $calls = [];

        public function sendEmailConfirmationRequest(string $toEmail, string $confirmUrl): void
        {
            $this->calls[] = ['to' => $toEmail, 'url' => $confirmUrl];
        }
    };
}

// ── requestChange — nominal ───────────────────────────────────────────────────

test('EmailChangeService::requestChange stocke email_pending et génère un token UUID v4', function (): void {
    $user = makeProfileUser('user-uuid-1', 'current@example.com');
    $repo = makeRepo(['user-uuid-1' => $user]);
    $spy = makeEmailSpy();

    $service = new EmailChangeService($repo, $spy, new NullLogger());
    $service->requestChange('user-uuid-1', 'new@example.com');

    // Un utilisateur a été sauvegardé
    expect($repo->saved)->toHaveCount(1);

    $savedUser = $repo->saved[0];
    expect($savedUser->getEmailPending())->toBe('new@example.com');
    expect($savedUser->getEmail()->getValue())->toBe('current@example.com'); // email courant inchangé

    // Token UUID v4 valide
    $token = $savedUser->getEmailPendingToken();
    expect($token)->not->toBeNull();
    expect($token)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

test('EmailChangeService::requestChange stocke email_pending_expires_at à +24h', function (): void {
    $user = makeProfileUser('user-uuid-1', 'current@example.com');
    $repo = makeRepo(['user-uuid-1' => $user]);
    $spy = makeEmailSpy();

    $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $service = new EmailChangeService($repo, $spy, new NullLogger());
    $service->requestChange('user-uuid-1', 'new@example.com');

    $savedUser = $repo->saved[0];
    $expiresAt = $savedUser->getEmailPendingExpiresAt();

    $after = new DateTimeImmutable('+24 hours', new DateTimeZone('UTC'));
    $before24h = $before->modify('+24 hours');

    expect($expiresAt)->not->toBeNull();
    // Doit être entre before+24h et after+24h (large marge)
    expect($expiresAt >= $before24h->modify('-1 second'))->toBeTrue();
    expect($expiresAt <= $after->modify('+1 second'))->toBeTrue();
});

test('EmailChangeService::requestChange envoie l\'email de confirmation à la nouvelle adresse', function (): void {
    $user = makeProfileUser('user-uuid-1', 'current@example.com');
    $repo = makeRepo(['user-uuid-1' => $user]);
    $spy = makeEmailSpy();

    $service = new EmailChangeService($repo, $spy, new NullLogger());
    $service->requestChange('user-uuid-1', 'new@example.com');

    expect($spy->calls)->toHaveCount(1);
    expect($spy->calls[0]['to'])->toBe('new@example.com');
    expect($spy->calls[0]['url'])->toContain('/profile/confirm-email/');
});

test('EmailChangeService::requestChange : email identique au courant → rien à faire', function (): void {
    $user = makeProfileUser('user-uuid-1', 'current@example.com');
    $repo = makeRepo(['user-uuid-1' => $user]);
    $spy = makeEmailSpy();

    $service = new EmailChangeService($repo, $spy, new NullLogger());
    $service->requestChange('user-uuid-1', 'current@example.com'); // même email

    expect($repo->saved)->toHaveCount(0); // pas de sauvegarde
    expect($spy->calls)->toHaveCount(0);  // pas d'email
});

// ── requestChange — email déjà utilisé ────────────────────────────────────────

test('EmailChangeService::requestChange lève EmailAlreadyInUseException si email appartient à un autre compte', function (): void {
    $user = makeProfileUser('user-uuid-1', 'current@example.com');
    $other = makeProfileUser('user-uuid-2', 'existing@example.com');
    $repo = makeRepo(
        ['user-uuid-1' => $user, 'user-uuid-2' => $other],
        ['existing@example.com' => $other],
    );
    $spy = makeEmailSpy();

    $service = new EmailChangeService($repo, $spy, new NullLogger());

    expect(fn () => $service->requestChange('user-uuid-1', 'existing@example.com'))
        ->toThrow(EmailAlreadyInUseException::class);

    expect($repo->saved)->toHaveCount(0);
    expect($spy->calls)->toHaveCount(0);
});

// ── confirmChange — nominal ───────────────────────────────────────────────────

test('EmailChangeService::confirmChange valide le token → email mis à jour + champs vidés', function (): void {
    $user = makeProfileUser('user-uuid-1', 'current@example.com');
    $token = 'valid-token-uuid-v4-xxxx';
    $expiresAt = new DateTimeImmutable('+2 hours', new DateTimeZone('UTC'));
    $user->requestEmailChange('new@example.com', $token, $expiresAt);

    $repo = makeRepo(['user-uuid-1' => $user]);
    $spy = makeEmailSpy();

    $service = new EmailChangeService($repo, $spy, new NullLogger());
    $result = $service->confirmChange($token);

    expect($result)->toBeTrue();
    expect($repo->saved)->toHaveCount(1);

    $savedUser = $repo->saved[0];
    expect($savedUser->getEmail()->getValue())->toBe('new@example.com');
    expect($savedUser->getEmailPending())->toBeNull();
    expect($savedUser->getEmailPendingToken())->toBeNull();
    expect($savedUser->getEmailPendingExpiresAt())->toBeNull();
});

// ── confirmChange — token expiré ──────────────────────────────────────────────

test('EmailChangeService::confirmChange token expiré → retourne false', function (): void {
    $user = makeProfileUser('user-uuid-1', 'current@example.com');
    $token = 'expired-token';
    $expiresAt = new DateTimeImmutable('-1 hour', new DateTimeZone('UTC')); // Expiré
    $user->requestEmailChange('new@example.com', $token, $expiresAt);

    $repo = makeRepo(['user-uuid-1' => $user]);
    $spy = makeEmailSpy();

    $service = new EmailChangeService($repo, $spy, new NullLogger());
    $result = $service->confirmChange($token);

    expect($result)->toBeFalse();
    expect($repo->saved)->toHaveCount(0); // pas de sauvegarde
});

// ── confirmChange — token inconnu ─────────────────────────────────────────────

test('EmailChangeService::confirmChange token inconnu → retourne false', function (): void {
    $user = makeProfileUser('user-uuid-1', 'current@example.com');
    $repo = makeRepo(['user-uuid-1' => $user]);
    $spy = makeEmailSpy();

    $service = new EmailChangeService($repo, $spy, new NullLogger());
    $result = $service->confirmChange('non-existent-token');

    expect($result)->toBeFalse();
    expect($repo->saved)->toHaveCount(0);
});
