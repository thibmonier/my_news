<?php

declare(strict_types=1);

use App\Application\Feed\ArticleClassifierService;
use App\Domain\Feed\ArticleCategory;
use Psr\Log\LoggerInterface;

/*
 * Tests unitaires — ArticleClassifierService (US-005/T-005-08).
 *
 * Valide :
 * - Nominal : classification par mots-clés → catégorie correcte retournée
 * - Fallback : aucun mot-clé connu → PRODUCTIVITY par défaut + log INFO
 * - Log INFO "category.fallback_applied" avec article_id, sans PII
 *
 * Gherkin couvert : US-005 alternatif 2 (fallback règles-métier si catégorie manquante).
 */

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeClassifierService(?LoggerInterface $logger = null): ArticleClassifierService
{
    return new ArticleClassifierService(
        $logger ?? new class implements LoggerInterface {
            public function emergency(string|Stringable $message, array $context = []): void
            {
            }

            public function alert(string|Stringable $message, array $context = []): void
            {
            }

            public function critical(string|Stringable $message, array $context = []): void
            {
            }

            public function error(string|Stringable $message, array $context = []): void
            {
            }

            public function warning(string|Stringable $message, array $context = []): void
            {
            }

            public function notice(string|Stringable $message, array $context = []): void
            {
            }

            public function info(string|Stringable $message, array $context = []): void
            {
            }

            public function debug(string|Stringable $message, array $context = []): void
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
            }
        },
    );
}

// ── Scénario nominal — classification par mots-clés ──────────────────────────

test('classify() retourne AI_INSIGHT pour un texte contenant "machine learning"', function (): void {
    $service = makeClassifierService();
    $category = $service->classify('test-uuid', 'New machine learning techniques improve NLP');

    expect($category)->toBe(ArticleCategory::AiInsight);
});

test('classify() retourne AI_INSIGHT pour un texte contenant "llm"', function (): void {
    $service = makeClassifierService();
    $category = $service->classify('test-uuid', 'LLM benchmarks show improvement in reasoning');

    expect($category)->toBe(ArticleCategory::AiInsight);
});

test('classify() retourne GEOPOLITICS pour un texte contenant "sanctions"', function (): void {
    $service = makeClassifierService();
    $category = $service->classify('test-uuid', 'New sanctions imposed on technology exports');

    expect($category)->toBe(ArticleCategory::Geopolitics);
});

test('classify() retourne RESEARCH pour un texte contenant "peer-reviewed"', function (): void {
    $service = makeClassifierService();
    $category = $service->classify('test-uuid', 'A peer-reviewed study published in Nature reveals new findings');

    expect($category)->toBe(ArticleCategory::Research);
});

test('classify() retourne SUSTAINABILITY pour un texte contenant "climate change"', function (): void {
    $service = makeClassifierService();
    $category = $service->classify('test-uuid', 'Climate change accelerates global warming trends');

    expect($category)->toBe(ArticleCategory::Sustainability);
});

test('classify() retourne PRODUCTIVITY pour un texte contenant "productivity"', function (): void {
    $service = makeClassifierService();
    $category = $service->classify('test-uuid', 'New productivity tools help remote teams collaborate');

    expect($category)->toBe(ArticleCategory::Productivity);
});

// ── Classification insensible à la casse ──────────────────────────────────────

test('classify() est insensible à la casse ("Machine Learning" → AI_INSIGHT)', function (): void {
    $service = makeClassifierService();
    $category = $service->classify('test-uuid', 'Machine Learning advances in 2026');

    expect($category)->toBe(ArticleCategory::AiInsight);
});

// ── Scénario fallback — aucun mot-clé → PRODUCTIVITY + log INFO ──────────────

test('classify() retourne PRODUCTIVITY par défaut si aucun mot-clé ne correspond', function (): void {
    $service = makeClassifierService();
    $category = $service->classify('test-uuid', 'Lorem ipsum dolor sit amet consectetur adipiscing elit');

    expect($category)->toBe(ArticleCategory::Productivity);
});

test('classify() log INFO "category.fallback_applied" quand aucune règle ne correspond', function (): void {
    $loggedMessages = [];
    $logger = new class($loggedMessages) implements LoggerInterface {
        public function __construct(private array &$messages)
        {
        }

        public function emergency(string|Stringable $message, array $context = []): void
        {
        }

        public function alert(string|Stringable $message, array $context = []): void
        {
        }

        public function critical(string|Stringable $message, array $context = []): void
        {
        }

        public function error(string|Stringable $message, array $context = []): void
        {
        }

        public function warning(string|Stringable $message, array $context = []): void
        {
        }

        public function notice(string|Stringable $message, array $context = []): void
        {
        }

        public function info(string|Stringable $message, array $context = []): void
        {
            $this->messages[] = $message;
        }

        public function debug(string|Stringable $message, array $context = []): void
        {
        }

        public function log($level, string|Stringable $message, array $context = []): void
        {
        }
    };

    $service = new ArticleClassifierService($logger);
    $service->classify('aabbccdd-0000-1111-2222-333344445555', 'Lorem ipsum dolor sit amet');

    expect($loggedMessages)->toContain('category.fallback_applied');
});

test('le log fallback contient article_id et category (sans PII)', function (): void {
    $loggedContexts = [];
    $logger = new class($loggedContexts) implements LoggerInterface {
        public function __construct(private array &$contexts)
        {
        }

        public function emergency(string|Stringable $message, array $context = []): void
        {
        }

        public function alert(string|Stringable $message, array $context = []): void
        {
        }

        public function critical(string|Stringable $message, array $context = []): void
        {
        }

        public function error(string|Stringable $message, array $context = []): void
        {
        }

        public function warning(string|Stringable $message, array $context = []): void
        {
        }

        public function notice(string|Stringable $message, array $context = []): void
        {
        }

        public function info(string|Stringable $message, array $context = []): void
        {
            $this->contexts[] = $context;
        }

        public function debug(string|Stringable $message, array $context = []): void
        {
        }

        public function log($level, string|Stringable $message, array $context = []): void
        {
        }
    };

    $articleId = 'aabbccdd-0000-1111-2222-333344445555';
    $service = new ArticleClassifierService($logger);
    $service->classify($articleId, 'Lorem ipsum dolor sit amet');

    expect($loggedContexts)->not->toBeEmpty()
        ->and($loggedContexts[0]['article_id'])->toBe($articleId)
        ->and($loggedContexts[0]['category'])->toBe(ArticleCategory::Productivity->value)
        ->and($loggedContexts[0]['event'])->toBe('category.fallback_applied');
});

test('classify() ne log PAS quand un mot-clé correspond (pas de fallback)', function (): void {
    $loggedMessages = [];
    $logger = new class($loggedMessages) implements LoggerInterface {
        public function __construct(private array &$messages)
        {
        }

        public function emergency(string|Stringable $message, array $context = []): void
        {
        }

        public function alert(string|Stringable $message, array $context = []): void
        {
        }

        public function critical(string|Stringable $message, array $context = []): void
        {
        }

        public function error(string|Stringable $message, array $context = []): void
        {
        }

        public function warning(string|Stringable $message, array $context = []): void
        {
        }

        public function notice(string|Stringable $message, array $context = []): void
        {
        }

        public function info(string|Stringable $message, array $context = []): void
        {
            $this->messages[] = $message;
        }

        public function debug(string|Stringable $message, array $context = []): void
        {
        }

        public function log($level, string|Stringable $message, array $context = []): void
        {
        }
    };

    $service = new ArticleClassifierService($logger);
    $service->classify('test-uuid', 'GPT-5 launch announcement from OpenAI');

    expect($loggedMessages)->not->toContain('category.fallback_applied');
});
