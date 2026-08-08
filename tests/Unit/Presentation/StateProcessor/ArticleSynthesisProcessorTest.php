<?php

declare(strict_types=1);

use ApiPlatform\Metadata\Post;
use App\Application\Quota\QuotaService;
use App\Application\Quota\UserUuidResolverInterface;
use App\Domain\Feed\ArticleDTO;
use App\Domain\Feed\ArticleRepositoryInterface;
use App\Domain\Quota\QuotaCounterInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;
use App\Domain\Synthesis\InvalidSynthesisUrlException;
use App\Domain\Synthesis\SynthesisRequest;
use App\Domain\Synthesis\SynthesisResponse;
use App\Domain\Synthesis\SynthesisResponseWithCacheStatus;
use App\Domain\Synthesis\SynthesisServiceInterface;
use App\Domain\Synthesis\SynthesisUnavailableException;
use App\Presentation\ApiResource\SynthesisResource;
use App\Presentation\StateProcessor\ArticleSynthesisProcessor;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/*
 * Unit tests — ArticleSynthesisProcessor (Presentation layer)
 *
 * POST /api/v1/articles/{id}/synthesize — synthèse Mistral par ID d'article (US-033 + US-010) :
 *   - Nominal : article existant → SynthesisResource "BRIEFLY AI:" (niveau concise)
 *   - Article inconnu → NotFoundHttpException (404)
 *   - URL article invalide (SSRF) → UnprocessableEntityHttpException (422)
 *   - Mistral KO → ServiceUnavailableHttpException (503) sans stacktrace
 *   - Quota épuisé → TooManyRequestsHttpException (429)
 *   - Redis KO → ServiceUnavailableHttpException (503)
 *   - Non authentifié → AccessDeniedException
 */

// ── Stubs ──────────────────────────────────────────────────────────────────────

function articleQuotaCounterStub(int $count = 0, bool $throwOnGet = false): QuotaCounterInterface
{
    return new class($count, $throwOnGet) implements QuotaCounterInterface {
        private int $current;

        public function __construct(int $count, private readonly bool $throw)
        {
            $this->current = $count;
        }

        public function getCount(string $userUuid, string $dateUtc): int
        {
            if ($this->throw) {
                throw new QuotaServiceUnavailableException('mock Redis KO');
            }

            return $this->current;
        }

        public function incrementAndExpire(string $userUuid, string $dateUtc, int $expireAt): int
        {
            return ++$this->current;
        }
    };
}

function articleUuidResolverStub(?string $uuid): UserUuidResolverInterface
{
    return new class($uuid) implements UserUuidResolverInterface {
        public function __construct(private readonly ?string $uuid)
        {
        }

        public function getCurrentUserUuid(): ?string
        {
            return $this->uuid;
        }
    };
}

function articleSynthesisServiceStub(
    bool $throwInvalid = false,
    bool $throwUnavailable = false,
): SynthesisServiceInterface {
    return new class($throwInvalid, $throwUnavailable) implements SynthesisServiceInterface {
        public function __construct(
            private readonly bool $throwInvalid,
            private readonly bool $throwUnavailable,
        ) {
        }

        public function synthesize(SynthesisRequest $request): SynthesisResponseWithCacheStatus
        {
            if ($this->throwInvalid) {
                throw new InvalidSynthesisUrlException('URL invalide');
            }

            if ($this->throwUnavailable) {
                throw new SynthesisUnavailableException('mock Mistral KO');
            }

            $response = new SynthesisResponse(
                content: 'BRIEFLY AI: Synthèse concise de l\'article.',
                keyPoints: ['01 First point', '02 Second point', '03 Third point'],
                sources: ['Test Source'],
                originalUrl: $request->url,
                isPartial: false,
            );

            return new SynthesisResponseWithCacheStatus($response, SynthesisResponseWithCacheStatus::MISS);
        }
    };
}

/** Repository stub : résout une seule URL pour l'ID connu, null sinon. */
function articleRepositoryStub(?string $url, string $knownId = 'article-123'): ArticleRepositoryInterface
{
    return new class($url, $knownId) implements ArticleRepositoryInterface {
        public function __construct(private readonly ?string $url, private readonly string $knownId)
        {
        }

        public function findUrlById(string $id): ?string
        {
            return $id === $this->knownId ? $this->url : null;
        }

        public function saveIgnoringDuplicate(ArticleDTO $dto): ?string
        {
            return null;
        }

        public function findPotentialDuplicates(int $simhash, \DateTimeImmutable $publishedAt, int $threshold): array
        {
            return [];
        }

        public function markAsDuplicate(string $articleId, int $simhash, string $duplicateOfId): void
        {
        }

        public function updateTitleSimHash(string $articleId, int $simhash): void
        {
        }

        public function findPaginatedWithSourceName(int $page, int $perPage): array
        {
            return [];
        }

        public function countAll(): int
        {
            return 0;
        }
    };
}

function makeArticleSynthesisProcessor(
    SynthesisServiceInterface $synthesisService,
    ?string $articleUrl = 'https://example.com/article',
    int $quotaCount = 0,
    bool $redisKo = false,
    ?string $userUuid = 'test-user-uuid',
): ArticleSynthesisProcessor {
    return new ArticleSynthesisProcessor(
        synthesisService: $synthesisService,
        articleRepository: articleRepositoryStub($articleUrl),
        quotaService: new QuotaService(articleQuotaCounterStub($quotaCount, $redisKo)),
        userUuidResolver: articleUuidResolverStub($userUuid),
        logger: new NullLogger(),
    );
}

function articleSynthesizeOperation(): Post
{
    return new Post(uriTemplate: '/v1/articles/{id}/synthesize');
}

/** @return array<string, mixed> */
function articleUriVariables(string $id = 'article-123'): array
{
    return ['id' => $id];
}

// ── Scénario nominal ──────────────────────────────────────────────────────────

test('process retourne SynthesisResource avec préfixe "BRIEFLY AI:" pour article existant', function (): void {
    $processor = makeArticleSynthesisProcessor(articleSynthesisServiceStub());

    $result = $processor->process(null, articleSynthesizeOperation(), articleUriVariables());

    expect($result)->toBeInstanceOf(SynthesisResource::class);
    expect($result->content)->toContain('BRIEFLY AI:');
});

test('process retourne les champs riches Mistral (keyPoints, sources, articleId)', function (): void {
    $processor = makeArticleSynthesisProcessor(articleSynthesisServiceStub());

    $result = $processor->process(null, articleSynthesizeOperation(), articleUriVariables('article-123'));

    expect($result->keyPoints)->toHaveCount(3);
    expect($result->sources)->not->toBeEmpty();
    expect($result->articleId)->toBe('article-123');
    expect($result->level)->toBe('concise');
});

// ── Article inconnu → 404 ─────────────────────────────────────────────────────

test('process lève NotFoundHttpException si article inconnu', function (): void {
    $processor = makeArticleSynthesisProcessor(articleSynthesisServiceStub());

    expect(static fn () => $processor->process(null, articleSynthesizeOperation(), articleUriVariables('unknown-id')))
        ->toThrow(NotFoundHttpException::class);
});

test('process lève NotFoundHttpException si id absent des uriVariables', function (): void {
    $processor = makeArticleSynthesisProcessor(articleSynthesisServiceStub());

    expect(static fn () => $processor->process(null, articleSynthesizeOperation(), []))
        ->toThrow(NotFoundHttpException::class);
});

// ── Erreur URL invalide (SSRF) → 422 ──────────────────────────────────────────

test('process lève UnprocessableEntityHttpException si URL article invalide (SSRF)', function (): void {
    $processor = makeArticleSynthesisProcessor(articleSynthesisServiceStub(throwInvalid: true));

    expect(static fn () => $processor->process(null, articleSynthesizeOperation(), articleUriVariables()))
        ->toThrow(UnprocessableEntityHttpException::class);
});

// ── Mistral KO → 503 sans stacktrace ──────────────────────────────────────────

test('process lève ServiceUnavailableHttpException si Mistral KO', function (): void {
    $processor = makeArticleSynthesisProcessor(articleSynthesisServiceStub(throwUnavailable: true));

    expect(static fn () => $processor->process(null, articleSynthesizeOperation(), articleUriVariables()))
        ->toThrow(ServiceUnavailableHttpException::class);
});

test('ServiceUnavailableHttpException ne contient pas de stacktrace (OWASP A05)', function (): void {
    $processor = makeArticleSynthesisProcessor(articleSynthesisServiceStub(throwUnavailable: true));

    try {
        $processor->process(null, articleSynthesizeOperation(), articleUriVariables());
        expect(true)->toBeFalse('Exception attendue non levée');
    } catch (ServiceUnavailableHttpException $e) {
        expect($e->getMessage())->not->toContain('SynthesisUnavailableException');
        expect($e->getMessage())->toContain('indisponible');
    }
});

// ── Quota ────────────────────────────────────────────────────────────────────

test('process lève TooManyRequestsHttpException si quota épuisé (count=3)', function (): void {
    $processor = makeArticleSynthesisProcessor(articleSynthesisServiceStub(), quotaCount: 3);

    expect(static fn () => $processor->process(null, articleSynthesizeOperation(), articleUriVariables()))
        ->toThrow(TooManyRequestsHttpException::class);
});

test('TooManyRequestsHttpException a le header X-Quota-Remaining: 0', function (): void {
    $processor = makeArticleSynthesisProcessor(articleSynthesisServiceStub(), quotaCount: 3);

    try {
        $processor->process(null, articleSynthesizeOperation(), articleUriVariables());
        expect(true)->toBeFalse('Exception attendue non levée');
    } catch (TooManyRequestsHttpException $e) {
        expect($e->getHeaders()['X-Quota-Remaining'])->toBe('0');
    }
});

test('process lève ServiceUnavailableHttpException si Redis KO', function (): void {
    $processor = makeArticleSynthesisProcessor(articleSynthesisServiceStub(), redisKo: true);

    expect(static fn () => $processor->process(null, articleSynthesizeOperation(), articleUriVariables()))
        ->toThrow(ServiceUnavailableHttpException::class);
});

// ── Authentification ──────────────────────────────────────────────────────────

test('process lève AccessDeniedException si utilisateur non authentifié', function (): void {
    $processor = makeArticleSynthesisProcessor(articleSynthesisServiceStub(), userUuid: null);

    expect(static fn () => $processor->process(null, articleSynthesizeOperation(), articleUriVariables()))
        ->toThrow(AccessDeniedException::class);
});
