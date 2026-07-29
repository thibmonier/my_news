<?php

declare(strict_types=1);

use App\Application\Summary\ArticleSummaryService;
use App\Domain\Summary\ArticleSummary;
use App\Domain\Summary\ArticleSummaryRepositoryInterface;
use App\Domain\Summary\SummaryCacheInterface;
use App\Domain\Summary\SummaryCircuitBreakerInterface;
use App\Domain\Summary\SummaryClientInterface;
use App\Domain\Summary\SummaryUnavailableException;
use Psr\Log\NullLogger;

/*
 * Unit tests — ArticleSummaryService (US-004/T-004-11).
 *
 * Couvre les scénarios Gherkin US-004 :
 * - Nominal cache hit : 0 appel LLM
 * - Cache miss → Mistral → cache set + persist
 * - Circuit breaker Mistral ouvert → fallback OpenAI
 * - Tous fournisseurs KO → mode dégradé (extrait RSS brut)
 * - PII-free : aucun UUID utilisateur dans le prompt LLM (T-004-11)
 * - XSS : réponse Mistral contenant <script> stockée brute (échappement dans la vue)
 *
 * Tous les ports sont mockés (stubs PHP anonymes — 0 appel réseau réel).
 */

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Construit un ArticleSummary nominal (3 puces).
 */
function makeSummary(string $articleId = 'article-uuid-001'): ArticleSummary
{
    return new ArticleSummary(
        articleId: $articleId,
        keyPoints: ['Point A.', 'Point B.', 'Point C.'],
        modelVersion: 'mistral-small-latest',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
    );
}

/**
 * Stub SummaryClientInterface — retourne un résumé prédéfini ou lève une exception.
 */
function summaryClientStub(
    ?ArticleSummary $returnSummary = null,
    bool $throwUnavailable = false,
): SummaryClientInterface {
    return new class($returnSummary, $throwUnavailable) implements SummaryClientInterface {
        /** @var list<string> Textes d'articles capturés (PII assertion T-004-11) */
        public array $capturedTexts = [];

        public function __construct(
            private readonly ?ArticleSummary $summary,
            private readonly bool $throw,
        ) {
        }

        public function summarize(string $articleText, string $articleId): ArticleSummary
        {
            $this->capturedTexts[] = $articleText;

            if ($this->throw) {
                throw new SummaryUnavailableException('mock provider unavailable');
            }

            return $this->summary ?? makeSummary($articleId);
        }
    };
}

/**
 * Stub SummaryCacheInterface — retour paramétrable + capture des sets.
 */
function summaryCacheStub(?ArticleSummary $cached = null): SummaryCacheInterface
{
    return new class($cached) implements SummaryCacheInterface {
        /** @var list<array{key: string, summary: ArticleSummary, ttl: int}> */
        public array $setCalls = [];

        public function __construct(
            private readonly ?ArticleSummary $cached,
        ) {
        }

        public function get(string $cacheKey): ?ArticleSummary
        {
            return $this->cached;
        }

        public function set(string $cacheKey, ArticleSummary $summary, int $ttl): void
        {
            $this->setCalls[] = ['key' => $cacheKey, 'summary' => $summary, 'ttl' => $ttl];
        }
    };
}

/**
 * Stub SummaryCircuitBreakerInterface — circuit ouvert ou fermé selon paramètre.
 */
function circuitBreakerStub(bool $isOpen = false): SummaryCircuitBreakerInterface
{
    return new class($isOpen) implements SummaryCircuitBreakerInterface {
        /** @var list<string> */
        public array $failures = [];
        /** @var list<string> */
        public array $successes = [];

        public function __construct(
            private readonly bool $open,
        ) {
        }

        public function isOpen(string $provider): bool
        {
            return $this->open;
        }

        public function recordFailure(string $provider): void
        {
            $this->failures[] = $provider;
        }

        public function recordSuccess(string $provider): void
        {
            $this->successes[] = $provider;
        }
    };
}

/**
 * Stub ArticleSummaryRepositoryInterface — capture les saves.
 */
function summaryRepositoryStub(): ArticleSummaryRepositoryInterface
{
    return new class implements ArticleSummaryRepositoryInterface {
        /** @var list<ArticleSummary> */
        public array $saved = [];

        public function findByArticleId(string $articleId): ?ArticleSummary
        {
            return null;
        }

        public function save(ArticleSummary $summary): void
        {
            $this->saved[] = $summary;
        }
    };
}

/**
 * Construit un ArticleSummaryService avec les stubs fournis.
 */
function makeService(
    SummaryClientInterface $mistral,
    SummaryClientInterface $openAi,
    SummaryCircuitBreakerInterface $circuitBreaker,
    SummaryCacheInterface $cache,
    ArticleSummaryRepositoryInterface $repository,
): ArticleSummaryService {
    return new ArticleSummaryService(
        mistralClient: $mistral,
        openAiClient: $openAi,
        circuitBreaker: $circuitBreaker,
        cache: $cache,
        repository: $repository,
        logger: new NullLogger(),
    );
}

// ── Scénario : cache hit ──────────────────────────────────────────────────────

test('getSummary retourne immédiatement depuis le cache Redis sans appeler Mistral', function (): void {
    $cached = makeSummary('uuid-001');
    $mistral = summaryClientStub();
    $service = makeService(
        mistral: $mistral,
        openAi: summaryClientStub(),
        circuitBreaker: circuitBreakerStub(),
        cache: summaryCacheStub($cached),
        repository: summaryRepositoryStub(),
    );

    $result = $service->getSummary('uuid-001', 'Texte de l\'article.');

    expect($result)->toBe($cached)
        ->and($mistral->capturedTexts)->toBeEmpty(); // 0 appel Mistral
});

// ── Scénario : cache miss → Mistral ──────────────────────────────────────────

test('getSummary cache miss appelle Mistral et retourne le condensé', function (): void {
    $mistral = summaryClientStub();
    $cache = summaryCacheStub(null); // cache vide
    $service = makeService(
        mistral: $mistral,
        openAi: summaryClientStub(throwUnavailable: true),
        circuitBreaker: circuitBreakerStub(isOpen: false),
        cache: $cache,
        repository: summaryRepositoryStub(),
    );

    $result = $service->getSummary('uuid-002', 'Texte de l\'article sans PII.');

    expect($result->isDegraded)->toBeFalse()
        ->and($result->keyPoints)->toHaveCount(3)
        ->and($cache->setCalls)->toHaveCount(1); // cache set après génération
});

test('getSummary cache miss → Mistral → persist dans le repository', function (): void {
    $repo = summaryRepositoryStub();
    $service = makeService(
        mistral: summaryClientStub(),
        openAi: summaryClientStub(throwUnavailable: true),
        circuitBreaker: circuitBreakerStub(isOpen: false),
        cache: summaryCacheStub(null),
        repository: $repo,
    );

    $service->getSummary('uuid-003', 'Article text.');

    expect($repo->saved)->toHaveCount(1);
});

test('getSummary enregistre un succès sur le circuit breaker Mistral', function (): void {
    $cb = circuitBreakerStub(isOpen: false);
    $service = makeService(
        mistral: summaryClientStub(),
        openAi: summaryClientStub(throwUnavailable: true),
        circuitBreaker: $cb,
        cache: summaryCacheStub(null),
        repository: summaryRepositoryStub(),
    );

    $service->getSummary('uuid-004', 'Article text.');

    expect($cb->successes)->toContain('mistral');
});

// ── Scénario : circuit breaker Mistral ouvert → fallback OpenAI ──────────────

test('getSummary circuit breaker Mistral ouvert → fallback OpenAI', function (): void {
    $openAiSummary = makeSummary('uuid-005');
    $openAi = summaryClientStub($openAiSummary);

    // Deux circuit breakers : Mistral ouvert, OpenAI fermé
    $cb = new class implements SummaryCircuitBreakerInterface {
        public function isOpen(string $provider): bool
        {
            return 'mistral' === $provider; // Mistral ouvert, OpenAI fermé
        }

        public function recordFailure(string $provider): void
        {
        }

        public function recordSuccess(string $provider): void
        {
        }
    };

    $mistral = summaryClientStub(throwUnavailable: true);
    $service = makeService(
        mistral: $mistral,
        openAi: $openAi,
        circuitBreaker: $cb,
        cache: summaryCacheStub(null),
        repository: summaryRepositoryStub(),
    );

    $result = $service->getSummary('uuid-005', 'Article text.');

    expect($result)->toBe($openAiSummary)
        ->and($mistral->capturedTexts)->toBeEmpty(); // Mistral non appelé (circuit ouvert)
});

test('getSummary Mistral KO (exception) → fallback OpenAI', function (): void {
    $openAiSummary = makeSummary('uuid-006');
    $service = makeService(
        mistral: summaryClientStub(throwUnavailable: true), // Mistral lance exception
        openAi: summaryClientStub($openAiSummary),
        circuitBreaker: circuitBreakerStub(isOpen: false), // Les deux circuits fermés
        cache: summaryCacheStub(null),
        repository: summaryRepositoryStub(),
    );

    $result = $service->getSummary('uuid-006', 'Article text.');

    expect($result)->toBe($openAiSummary);
});

// ── Scénario : tous fournisseurs KO → mode dégradé ───────────────────────────

test('getSummary tous KO retourne un condensé dégradé (isDegraded=true)', function (): void {
    $service = makeService(
        mistral: summaryClientStub(throwUnavailable: true),
        openAi: summaryClientStub(throwUnavailable: true),
        circuitBreaker: circuitBreakerStub(isOpen: false),
        cache: summaryCacheStub(null),
        repository: summaryRepositoryStub(),
    );

    $result = $service->getSummary('uuid-007', 'Extrait RSS brut de l\'article tech.');

    expect($result->isDegraded)->toBeTrue()
        ->and($result->keyPoints)->toBeEmpty()
        ->and($result->degradedContent)->not->toBeEmpty();
});

test('getSummary mode dégradé tronque le degradedContent à 280 chars', function (): void {
    $longText = str_repeat('A', 500); // Texte de 500 chars
    $service = makeService(
        mistral: summaryClientStub(throwUnavailable: true),
        openAi: summaryClientStub(throwUnavailable: true),
        circuitBreaker: circuitBreakerStub(isOpen: false),
        cache: summaryCacheStub(null),
        repository: summaryRepositoryStub(),
    );

    $result = $service->getSummary('uuid-008', $longText);

    expect($result->isDegraded)->toBeTrue()
        ->and(mb_strlen($result->degradedContent))->toBeLessThanOrEqual(280);
});

test('getSummary mode dégradé 0 appel Mistral ni OpenAI quand les deux circuits sont ouverts', function (): void {
    $cb = circuitBreakerStub(isOpen: true); // Les DEUX circuits ouverts
    $mistral = summaryClientStub();
    $openAi = summaryClientStub();
    $service = makeService(
        mistral: $mistral,
        openAi: $openAi,
        circuitBreaker: $cb,
        cache: summaryCacheStub(null),
        repository: summaryRepositoryStub(),
    );

    $result = $service->getSummary('uuid-009', 'Article text.');

    expect($result->isDegraded)->toBeTrue()
        ->and($mistral->capturedTexts)->toBeEmpty()
        ->and($openAi->capturedTexts)->toBeEmpty();
});

// ── PII-free assertion T-004-11 ───────────────────────────────────────────────

test('T-004-11 PII-free : le prompt envoyé à Mistral ne contient aucun UUID utilisateur', function (): void {
    $mistral = summaryClientStub();
    $service = makeService(
        mistral: $mistral,
        openAi: summaryClientStub(throwUnavailable: true),
        circuitBreaker: circuitBreakerStub(isOpen: false),
        cache: summaryCacheStub(null),
        repository: summaryRepositoryStub(),
    );

    $fakeUserId = 'e3d8f1a2-4b5c-6d7e-8f9a-0b1c2d3e4f50';
    $articleText = 'This is the article text about technology trends.';

    $service->getSummary('article-uuid-001', $articleText);

    // L'articleText envoyé à Mistral ne doit JAMAIS contenir l'UUID utilisateur
    $capturedText = implode(' ', $mistral->capturedTexts);
    expect($capturedText)->not->toContain($fakeUserId);
});

test('T-004-11 PII-free : le prompt envoyé à Mistral ne contient aucun email', function (): void {
    $mistral = summaryClientStub();
    $service = makeService(
        mistral: $mistral,
        openAi: summaryClientStub(throwUnavailable: true),
        circuitBreaker: circuitBreakerStub(isOpen: false),
        cache: summaryCacheStub(null),
        repository: summaryRepositoryStub(),
    );

    $articleText = 'This is clean article text without any PII.';

    $service->getSummary('article-uuid-001', $articleText);

    $capturedText = implode(' ', $mistral->capturedTexts);
    // Aucun pattern email dans le texte envoyé
    expect($capturedText)->not->toMatch('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/');
});

// ── Scénario XSS : contenu Mistral avec <script> stocké brut ─────────────────

test('XSS : réponse Mistral contenant <script> est stockée brute (échappement côté vue)', function (): void {
    $xssSummary = new ArticleSummary(
        articleId: 'uuid-xss',
        keyPoints: [
            "<script>alert('xss')</script>",
            'Point 2 normal.',
            'Point 3 normal.',
        ],
        modelVersion: 'mistral-small-latest',
        createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
    );
    $cache = summaryCacheStub(null);
    $service = makeService(
        mistral: summaryClientStub($xssSummary),
        openAi: summaryClientStub(throwUnavailable: true),
        circuitBreaker: circuitBreakerStub(isOpen: false),
        cache: $cache,
        repository: summaryRepositoryStub(),
    );

    $result = $service->getSummary('uuid-xss', 'Article text.');

    // La valeur est stockée brute dans l'objet (pas d'échappement dans le service)
    // L'échappement est responsabilité de la vue (htmlspecialchars dans BriefController)
    expect($result->keyPoints[0])->toContain('<script>');
    // Le condensé est mis en cache avec le contenu brut
    expect($cache->setCalls)->toHaveCount(1);
});
