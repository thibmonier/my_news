<?php

declare(strict_types=1);

use App\Presentation\Validator\SsrfSafeUrl;
use App\Presentation\Validator\SsrfSafeUrlValidator;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/*
 * Tests unitaires — SsrfSafeUrlValidator
 *
 * Vérifie la protection SSRF (OWASP A01:2025) :
 * - Rejet des URLs HTTP (non-HTTPS)
 * - Rejet des IPs RFC-1918 (10.x, 192.168.x, 172.16-31.x)
 * - Rejet du loopback (127.x)
 * - Rejet du link-local / metadata cloud (169.254.x)
 * - Rejet du hostname localhost
 * - Acceptation des URLs HTTPS publiques valides
 * - Acceptation des valeurs null/vide (gérées par @Assert\NotBlank)
 */

uses(PHPUnit\Framework\TestCase::class);

// ── Helpers utilisant des stubs anonymes (pas de createMock en scope global) ──

/**
 * Stub de violation : capture si buildViolation() a été appelé.
 * N'utilise PAS PHPUnit mocks pour éviter le scope global.
 */
function makeViolatingContext(): ExecutionContextInterface
{
    return new class implements ExecutionContextInterface {
        public bool $violationAdded = false;

        public function buildViolation(string $message, array $parameters = []): ConstraintViolationBuilderInterface
        {
            return new class($this) implements ConstraintViolationBuilderInterface {
                public function __construct(private readonly object $ctx)
                {
                }

                public function addViolation(): void
                {
                    $this->ctx->violationAdded = true;
                }

                public function atPath(string $path): static
                {
                    return $this;
                }

                public function setParameter(string $key, string $value): static
                {
                    return $this;
                }

                public function setParameters(array $parameters): static
                {
                    return $this;
                }

                public function setTranslationDomain(?string $translationDomain): static
                {
                    return $this;
                }

                public function setInvalidValue(mixed $invalidValue): static
                {
                    return $this;
                }

                public function setPlural(int $number): static
                {
                    return $this;
                }

                public function setCode(?string $code): static
                {
                    return $this;
                }

                public function setCause(mixed $cause): static
                {
                    return $this;
                }

                public function disableTranslation(): static
                {
                    return $this;
                }
            };
        }

        public function getViolations(): Symfony\Component\Validator\ConstraintViolationListInterface
        {
            return new Symfony\Component\Validator\ConstraintViolationList();
        }

        public function getObject(): ?object
        {
            return null;
        }

        public function setNode(mixed $value, ?object $object, ?Symfony\Component\Validator\Mapping\MetadataInterface $metadata, string $propertyPath): void
        {
        }

        public function setGroup(?string $group): void
        {
        }

        public function setConstraint(Symfony\Component\Validator\Constraint $constraint): void
        {
        }

        public function addViolation(string $message, array $parameters = []): void
        {
        }

        public function getValue(): mixed
        {
            return null;
        }

        public function getMetadata(): ?Symfony\Component\Validator\Mapping\MetadataInterface
        {
            return null;
        }

        public function getGroup(): ?string
        {
            return null;
        }

        public function getClassName(): ?string
        {
            return null;
        }

        public function getPropertyName(): ?string
        {
            return null;
        }

        public function getPropertyPath(string $subPath = ''): string
        {
            return $subPath;
        }

        public function getRoot(): mixed
        {
            return null;
        }

        public function isConstraintValidated(string $cacheKey, string $constraintHash): bool
        {
            return false;
        }

        public function markConstraintAsValidated(string $cacheKey, string $constraintHash): void
        {
        }

        public function markGroupAsValidated(string $cacheKey, string $groupHash): void
        {
        }

        public function isGroupValidated(string $cacheKey, string $groupHash): bool
        {
            return false;
        }

        public function markObjectAsInitialized(string $cacheKey): void
        {
        }

        public function isObjectInitialized(string $cacheKey): bool
        {
            return false;
        }

        public function getValidator(): Symfony\Component\Validator\Validator\ValidatorInterface
        {
            throw new LogicException('Not needed in stub.');
        }
    };
}

function makeNonViolatingContext(): ExecutionContextInterface
{
    return new class implements ExecutionContextInterface {
        public bool $violationAdded = false;

        public function buildViolation(string $message, array $parameters = []): ConstraintViolationBuilderInterface
        {
            // Should NOT be called for valid URLs
            $this->violationAdded = true;

            return new class implements ConstraintViolationBuilderInterface {
                public function addViolation(): void
                {
                }

                public function atPath(string $path): static
                {
                    return $this;
                }

                public function setParameter(string $key, string $value): static
                {
                    return $this;
                }

                public function setParameters(array $parameters): static
                {
                    return $this;
                }

                public function setTranslationDomain(?string $translationDomain): static
                {
                    return $this;
                }

                public function setInvalidValue(mixed $invalidValue): static
                {
                    return $this;
                }

                public function setPlural(int $number): static
                {
                    return $this;
                }

                public function setCode(?string $code): static
                {
                    return $this;
                }

                public function setCause(mixed $cause): static
                {
                    return $this;
                }

                public function disableTranslation(): static
                {
                    return $this;
                }
            };
        }

        public function getViolations(): Symfony\Component\Validator\ConstraintViolationListInterface
        {
            return new Symfony\Component\Validator\ConstraintViolationList();
        }

        public function getObject(): ?object
        {
            return null;
        }

        public function setNode(mixed $value, ?object $object, ?Symfony\Component\Validator\Mapping\MetadataInterface $metadata, string $propertyPath): void
        {
        }

        public function setGroup(?string $group): void
        {
        }

        public function setConstraint(Symfony\Component\Validator\Constraint $constraint): void
        {
        }

        public function addViolation(string $message, array $parameters = []): void
        {
        }

        public function getValue(): mixed
        {
            return null;
        }

        public function getMetadata(): ?Symfony\Component\Validator\Mapping\MetadataInterface
        {
            return null;
        }

        public function getGroup(): ?string
        {
            return null;
        }

        public function getClassName(): ?string
        {
            return null;
        }

        public function getPropertyName(): ?string
        {
            return null;
        }

        public function getPropertyPath(string $subPath = ''): string
        {
            return $subPath;
        }

        public function getRoot(): mixed
        {
            return null;
        }

        public function isConstraintValidated(string $cacheKey, string $constraintHash): bool
        {
            return false;
        }

        public function markConstraintAsValidated(string $cacheKey, string $constraintHash): void
        {
        }

        public function markGroupAsValidated(string $cacheKey, string $groupHash): void
        {
        }

        public function isGroupValidated(string $cacheKey, string $groupHash): bool
        {
            return false;
        }

        public function markObjectAsInitialized(string $cacheKey): void
        {
        }

        public function isObjectInitialized(string $cacheKey): bool
        {
            return false;
        }

        public function getValidator(): Symfony\Component\Validator\Validator\ValidatorInterface
        {
            throw new LogicException('Not needed in stub.');
        }
    };
}

// ── Rejet schéma HTTP ──────────────────────────────────────────────────────

test('SsrfSafeUrl rejette une URL HTTP', function (): void {
    $ctx = makeViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    $validator->validate('http://example.com/feed.rss', new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeTrue();
});

test('SsrfSafeUrl rejette ftp://', function (): void {
    $ctx = makeViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    $validator->validate('ftp://example.com/feed.rss', new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeTrue();
});

// ── Rejet IPs privées RFC-1918 + réservées ────────────────────────────────

test('SsrfSafeUrl rejette 169.254.169.254 (metadata cloud AWS)', function (): void {
    $ctx = makeViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    $validator->validate('https://169.254.169.254/latest/meta-data/', new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeTrue();
});

test('SsrfSafeUrl rejette 192.168.1.1 (RFC-1918 Class C)', function (): void {
    $ctx = makeViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    $validator->validate('https://192.168.1.1/rss', new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeTrue();
});

test('SsrfSafeUrl rejette 10.0.0.1 (RFC-1918 Class A)', function (): void {
    $ctx = makeViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    $validator->validate('https://10.0.0.1/rss', new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeTrue();
});

test('SsrfSafeUrl rejette 172.16.0.1 (RFC-1918 Class B début)', function (): void {
    $ctx = makeViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    $validator->validate('https://172.16.0.1/rss', new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeTrue();
});

test('SsrfSafeUrl rejette 172.31.255.255 (RFC-1918 Class B fin)', function (): void {
    $ctx = makeViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    $validator->validate('https://172.31.255.255/rss', new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeTrue();
});

test('SsrfSafeUrl rejette 127.0.0.1 (loopback)', function (): void {
    $ctx = makeViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    $validator->validate('https://127.0.0.1/rss', new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeTrue();
});

test('SsrfSafeUrl rejette 0.0.0.0', function (): void {
    $ctx = makeViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    $validator->validate('https://0.0.0.0/rss', new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeTrue();
});

// ── Rejet hostnames réservés ─────────────────────────────────────────────

test('SsrfSafeUrl rejette localhost', function (): void {
    $ctx = makeViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    $validator->validate('https://localhost/feed', new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeTrue();
});

// ── Valeurs valides / neutres ─────────────────────────────────────────────

test('SsrfSafeUrl accepte une URL HTTPS avec hostname public', function (): void {
    $ctx = makeNonViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    // Si gethostbyname() ne résout pas (docker test), retourne hostname → accepté
    $validator->validate('https://www.technologyreview.com/feed/', new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeFalse();
});

test('SsrfSafeUrl ignore null (géré par @Assert\NotBlank)', function (): void {
    $ctx = makeNonViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    $validator->validate(null, new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeFalse();
});

test('SsrfSafeUrl ignore chaîne vide (géré par @Assert\NotBlank)', function (): void {
    $ctx = makeNonViolatingContext();
    $validator = new SsrfSafeUrlValidator();
    $validator->initialize($ctx);
    $validator->validate('', new SsrfSafeUrl());
    expect($ctx->violationAdded)->toBeFalse();
});
