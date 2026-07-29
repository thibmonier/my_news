<?php

declare(strict_types=1);

use App\Application\User\OAuthAuthenticate\OAuthAuthenticateCommand;
use App\Application\User\OAuthAuthenticate\OAuthAuthenticateHandler;
use App\Domain\User\Email;
use App\Domain\User\OAuthAccount;
use App\Domain\User\OAuthAccountRepositoryInterface;
use App\Domain\User\OAuthProvider;
use App\Domain\User\User;
use App\Domain\User\UserRepositoryInterface;

/*
 * Unit tests — OAuthAuthenticateHandler (Application layer)
 *
 * Couvre les scénarios Gherkin US-031 :
 *   - Scénario nominal (nouvel utilisateur Google) : 1 INSERT users + 1 INSERT oauth_accounts
 *   - Scénario fusion de compte (email existant) : 0 INSERT users + 1 INSERT oauth_accounts
 *   - Scénario reconnexion ((provider, providerId) déjà connu) : 0 INSERT, User existant retourné
 *   - GitHub email noreply : compte créé avec email noreply, fonctionnel
 *   - Consentement RGPD (consent_at) horodaté à la première connexion OAuth
 *
 * Utilise des stubs PHP anonymes (pas de Mockery — cohérence avec le reste du projet).
 */

// ── Stubs ──────────────────────────────────────────────────────────────────────

/**
 * Stub UserRepositoryInterface enregistrant les appels.
 */
function makeUserRepositoryStub(
    ?User $findByEmailReturn = null,
    ?User $findByIdReturn = null,
    ?User &$savedUser = null,
): UserRepositoryInterface {
    return new class($findByEmailReturn, $findByIdReturn, $savedUser) implements UserRepositoryInterface {
        private int $saveCallCount = 0;

        public function __construct(
            private readonly ?User $findByEmailReturn,
            private readonly ?User $findByIdReturn,
            private mixed &$savedUser,
        ) {
        }

        public function save(User $user): void
        {
            ++$this->saveCallCount;
            $this->savedUser = $user;
        }

        public function findByEmail(Email $email): ?User
        {
            return $this->findByEmailReturn;
        }

        public function emailExists(Email $email): bool
        {
            return null !== $this->findByEmailReturn;
        }

        public function findById(string $id): ?User
        {
            return $this->findByIdReturn;
        }

        public function getSaveCallCount(): int
        {
            return $this->saveCallCount;
        }
    };
}

/**
 * Stub OAuthAccountRepositoryInterface enregistrant les appels.
 */
function makeOAuthRepositoryStub(
    ?OAuthAccount $findByProviderAndIdReturn = null,
    ?OAuthAccount &$savedAccount = null,
    int &$saveCallCount = 0,
): OAuthAccountRepositoryInterface {
    return new class($findByProviderAndIdReturn, $savedAccount, $saveCallCount) implements OAuthAccountRepositoryInterface {
        public function __construct(
            private readonly ?OAuthAccount $findByProviderAndIdReturn,
            private mixed &$savedAccount,
            private mixed &$saveCallCount,
        ) {
        }

        public function save(OAuthAccount $account): void
        {
            ++$this->saveCallCount;
            $this->savedAccount = $account;
        }

        public function findByProviderAndId(OAuthProvider $provider, string $providerId): ?OAuthAccount
        {
            return $this->findByProviderAndIdReturn;
        }

        public function findByUserIdAndProvider(string $userId, OAuthProvider $provider): ?OAuthAccount
        {
            return null;
        }
    };
}

/**
 * Crée un User de test (domaine).
 */
function makeTestUser(string $email = 'test@example.com', string $id = 'aaaabbbb-cccc-dddd-eeee-000000000001'): User
{
    return new User(
        id: $id,
        email: new Email($email),
        passwordHash: 'argon2id_hash',
        fullName: 'Test User',
        createdAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        consentAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
    );
}

/**
 * Crée un OAuthAccount de test (domaine).
 */
function makeTestOAuthAccount(
    string $userId = 'aaaabbbb-cccc-dddd-eeee-000000000001',
    string $provider = 'google',
    string $providerId = 'google-user-id-123',
): OAuthAccount {
    return new OAuthAccount(
        id: 'bbbbbbbb-cccc-dddd-eeee-000000000002',
        userId: $userId,
        provider: new OAuthProvider($provider),
        providerId: $providerId,
        emailProvider: 'test@gmail.com',
        createdAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
    );
}

// ── Tests : nouvel utilisateur Google ──────────────────────────────────────────

test('Scénario nominal : nouvel utilisateur Google crée User + OAuthAccount', function (): void {
    // Arrange
    $savedUser = null;
    $savedAccount = null;
    $saveAccountCount = 0;

    $userRepo = makeUserRepositoryStub(
        findByEmailReturn: null,  // email inconnu
        savedUser: $savedUser,
    );
    $oauthRepo = makeOAuthRepositoryStub(
        findByProviderAndIdReturn: null, // providerId inconnu
        savedAccount: $savedAccount,
        saveCallCount: $saveAccountCount,
    );

    $handler = new OAuthAuthenticateHandler($userRepo, $oauthRepo);

    // Act
    ['user' => $user, 'isNew' => $isNew] = $handler->handle(
        new OAuthAuthenticateCommand(
            provider: 'google',
            providerId: 'google-user-id-123',
            email: 'thomas@gmail.com',
            fullName: 'Thomas Dupont',
        ),
    );

    // Assert
    expect($isNew)->toBeTrue()
        ->and($user->getEmail()->getValue())->toBe('thomas@gmail.com')
        ->and($user->getFullName())->toBe('Thomas Dupont')
        ->and($user->getId())->not->toBeEmpty()
        ->and($saveAccountCount)->toBe(1)
        ->and($savedAccount)->not->toBeNull()
        ->and($savedAccount->getProvider()->getValue())->toBe('google')
        ->and($savedAccount->getProviderId())->toBe('google-user-id-123')
        ->and($savedAccount->getUserId())->toBe($user->getId());
});

test('Nouvel utilisateur Google : consent_at est horodaté à la création (RGPD)', function (): void {
    $savedUser = null;
    $savedAccount = null;
    $saveCount = 0;
    $before = new DateTimeImmutable();

    $userRepo = makeUserRepositoryStub(findByEmailReturn: null, savedUser: $savedUser);
    $oauthRepo = makeOAuthRepositoryStub(findByProviderAndIdReturn: null, savedAccount: $savedAccount, saveCallCount: $saveCount);

    $handler = new OAuthAuthenticateHandler($userRepo, $oauthRepo);

    ['user' => $user] = $handler->handle(
        new OAuthAuthenticateCommand('google', 'gid-123', 'new@gmail.com', 'New User'),
    );

    $after = new DateTimeImmutable();

    expect($user->getConsentAt())->toBeGreaterThanOrEqual($before)
        ->and($user->getConsentAt())->toBeLessThanOrEqual($after);
});

test('Nouvel utilisateur Google : le mot de passe haché est non vide mais inutilisable', function (): void {
    $savedUser = null;
    $savedAccount = null;
    $saveCount = 0;

    $userRepo = makeUserRepositoryStub(findByEmailReturn: null, savedUser: $savedUser);
    $oauthRepo = makeOAuthRepositoryStub(findByProviderAndIdReturn: null, savedAccount: $savedAccount, saveCallCount: $saveCount);

    $handler = new OAuthAuthenticateHandler($userRepo, $oauthRepo);

    ['user' => $user] = $handler->handle(
        new OAuthAuthenticateCommand('google', 'gid-456', 'another@gmail.com', ''),
    );

    // Le hash commence par 'oauth_' — jamais un vrai mot de passe Argon2id
    expect($user->getPasswordHash())->toStartWith('oauth_')
        ->and(strlen($user->getPasswordHash()))->toBeGreaterThan(10);
});

// ── Tests : fusion de compte (email existant) ─────────────────────────────────

test('Scénario fusion : email OAuth = compte email/password existant → 0 nouveau User, 1 OAuthAccount', function (): void {
    // Arrange : utilisateur email/password existant
    $existingUser = makeTestUser('thomas@example.com');

    $savedUser = null;
    $savedAccount = null;
    $saveAccountCount = 0;

    $userRepo = makeUserRepositoryStub(
        findByEmailReturn: $existingUser, // email déjà connu
        savedUser: $savedUser,
    );
    $oauthRepo = makeOAuthRepositoryStub(
        findByProviderAndIdReturn: null, // providerId inconnu (première connexion OAuth)
        savedAccount: $savedAccount,
        saveCallCount: $saveAccountCount,
    );

    $handler = new OAuthAuthenticateHandler($userRepo, $oauthRepo);

    // Act
    ['user' => $user, 'isNew' => $isNew] = $handler->handle(
        new OAuthAuthenticateCommand(
            provider: 'google',
            providerId: 'google-user-id-999',
            email: 'thomas@example.com',
            fullName: 'Thomas',
        ),
    );

    // Assert : aucun nouveau User créé, OAuthAccount lié au compte existant
    expect($isNew)->toBeFalse()
        ->and($user->getId())->toBe($existingUser->getId())
        ->and($user->getEmail()->getValue())->toBe('thomas@example.com')
        ->and($savedUser)->toBeNull()   // userRepo.save() PAS appelé
        ->and($saveAccountCount)->toBe(1) // oauthRepo.save() appelé une fois
        ->and($savedAccount->getUserId())->toBe($existingUser->getId())
        ->and($savedAccount->getProvider()->getValue())->toBe('google');
});

// ── Tests : reconnexion (provider+providerId déjà connu) ─────────────────────

test('Reconnexion : (provider, providerId) déjà connu → User existant retourné, 0 INSERT', function (): void {
    // Arrange : OAuthAccount et User déjà persistés
    $existingUser = makeTestUser('thomas@gmail.com');
    $existingOAuth = makeTestOAuthAccount($existingUser->getId(), 'google', 'google-user-id-123');

    $savedUser = null;
    $savedAccount = null;
    $saveAccountCount = 0;

    $userRepo = makeUserRepositoryStub(
        findByEmailReturn: null,
        findByIdReturn: $existingUser, // User trouvé par ID
        savedUser: $savedUser,
    );
    $oauthRepo = makeOAuthRepositoryStub(
        findByProviderAndIdReturn: $existingOAuth, // OAuthAccount trouvé
        savedAccount: $savedAccount,
        saveCallCount: $saveAccountCount,
    );

    $handler = new OAuthAuthenticateHandler($userRepo, $oauthRepo);

    // Act
    ['user' => $user, 'isNew' => $isNew] = $handler->handle(
        new OAuthAuthenticateCommand(
            provider: 'google',
            providerId: 'google-user-id-123',
            email: 'thomas@gmail.com',
            fullName: 'Thomas',
        ),
    );

    // Assert : aucune insertion, User existant retourné
    expect($isNew)->toBeFalse()
        ->and($user->getId())->toBe($existingUser->getId())
        ->and($savedUser)->toBeNull()
        ->and($saveAccountCount)->toBe(0);
});

// ── Tests : GitHub email noreply (privacy mode) ───────────────────────────────

test('GitHub email noreply : compte créé avec email noreply, fonctionnel', function (): void {
    $savedUser = null;
    $savedAccount = null;
    $saveCount = 0;

    $userRepo = makeUserRepositoryStub(findByEmailReturn: null, savedUser: $savedUser);
    $oauthRepo = makeOAuthRepositoryStub(findByProviderAndIdReturn: null, savedAccount: $savedAccount, saveCallCount: $saveCount);

    $handler = new OAuthAuthenticateHandler($userRepo, $oauthRepo);

    $noreplyEmail = '123456+octocat@users.noreply.github.com';

    ['user' => $user, 'isNew' => $isNew] = $handler->handle(
        new OAuthAuthenticateCommand(
            provider: 'github',
            providerId: '123456',
            email: $noreplyEmail,
            fullName: 'Octocat',
        ),
    );

    expect($isNew)->toBeTrue()
        ->and($user->getEmail()->getValue())->toBe($noreplyEmail)
        ->and($savedAccount->getProvider()->getValue())->toBe('github')
        ->and($savedAccount->getProviderId())->toBe('123456');
});

// ── Tests : IDs uniques (UUID v4) ─────────────────────────────────────────────

test('Deux créations successives génèrent des UUID différents', function (): void {
    $ids = [];

    for ($i = 0; $i < 2; ++$i) {
        $savedUser = null;
        $savedAccount = null;
        $saveCount = 0;

        $userRepo = makeUserRepositoryStub(findByEmailReturn: null, savedUser: $savedUser);
        $oauthRepo = makeOAuthRepositoryStub(findByProviderAndIdReturn: null, savedAccount: $savedAccount, saveCallCount: $saveCount);

        $handler = new OAuthAuthenticateHandler($userRepo, $oauthRepo);

        ['user' => $user] = $handler->handle(
            new OAuthAuthenticateCommand('google', "gid-{$i}", "user{$i}@gmail.com", "User {$i}"),
        );

        $ids[] = $user->getId();
    }

    expect($ids[0])->not->toBe($ids[1]);
});

// ── Tests : domaine OAuthProvider ─────────────────────────────────────────────

test('OAuthProvider : provider invalide lève une exception', function (): void {
    expect(fn () => new OAuthProvider('linkedin'))->toThrow(InvalidArgumentException::class);
});

test('OAuthProvider : google et github sont valides', function (): void {
    expect(OAuthProvider::google()->getValue())->toBe('google')
        ->and(OAuthProvider::github()->getValue())->toBe('github');
});

// ── Tests : domaine OAuthAccount ──────────────────────────────────────────────

test('OAuthAccount : providerId vide lève une exception', function (): void {
    expect(fn () => new OAuthAccount(
        id: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        userId: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        provider: OAuthProvider::google(),
        providerId: '',
        emailProvider: 'test@gmail.com',
        createdAt: new DateTimeImmutable(),
    ))->toThrow(InvalidArgumentException::class, 'providerId');
});

test('OAuthAccount : emailProvider vide lève une exception', function (): void {
    expect(fn () => new OAuthAccount(
        id: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        userId: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        provider: OAuthProvider::google(),
        providerId: 'gid-123',
        emailProvider: '',
        createdAt: new DateTimeImmutable(),
    ))->toThrow(InvalidArgumentException::class);
});
