<?php

declare(strict_types=1);

use App\Domain\Brief\ArticleCandidateRepositoryInterface;
use App\Domain\Brief\BriefGenerationFailedEvent;
use App\Domain\Brief\BriefSelectorService;
use App\Domain\Brief\DailyBrief;
use App\Domain\Brief\DailyBriefRepositoryInterface;
use App\Domain\Brief\DailyBriefStatus;
use App\Domain\Feed\Article;
use App\Domain\Feed\ContentHash;
use Psr\Log\LoggerInterface;

/*
 * Tests unitaires — BriefSelectorService (service domaine)
 *
 * Tests purement unitaires : doubles de tests (stubs/mocks) pour les interfaces.
 * Aucune dépendance Doctrine, Symfony Kernel, ni I/O.
 *
 * Couvre les 4 scénarios Gherkin de US-002 :
 * - Nominal : 3 clusters distincts → 3 BriefStories avec scores > 0
 * - Alternatif 1 : 2 clusters seulement → 2 BriefStories + log WARNING
 * - Alternatif 2 : re-exécution même date → UPDATE (pas doublon)
 * - Erreur : 0 articles → BriefGenerationFailedEvent dispatché
 */

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeArticle(
    string $id,
    string $sourceId,
    ?string $clusterId = null,
    int $ageSeconds = 0,
    bool $isFullText = true,
    int $contentLength = 1000,
): Article {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $publishedAt = $now->modify("-{$ageSeconds} seconds");

    return new Article(
        id: $id,
        sourceId: $sourceId,
        title: "Article {$id}",
        url: "https://example.com/article/{$id}",
        contentHash: ContentHash::fromCanonicalUrl("https://example.com/article/{$id}"),
        publishedAt: $publishedAt,
        rawContent: str_repeat('x', $contentLength),
        fetchAt: $now,
        clusterId: $clusterId,
        isFullTextAccessible: $isFullText,
    );
}

/**
 * Crée le service + ses doubles de test.
 *
 * Retourne [$service, $saveBucket, $logBucket, $briefRepo].
 * - $saveBucket->briefs : liste des DailyBrief persistés via upsertForToday()
 * - $logBucket->messages : liste des messages loggés
 *
 * Les objets stdClass sont passés par handle (reference PHP implicite sur objets).
 * Toute mutation effectuée par le service (upsertForToday, logger) est donc visible
 * dans les variables renvoyées, même après l'appel à selectTopStories().
 *
 * @return array{BriefSelectorService, stdClass, stdClass, DailyBriefRepositoryInterface}
 */
function makeSelectorService(
    array $candidates,
    ?DailyBrief $existingBrief = null,
): array {
    $articleRepo = new class($candidates) implements ArticleCandidateRepositoryInterface {
        /** @param list<Article> $candidates */
        public function __construct(private readonly array $candidates)
        {
        }

        /** @return list<Article> */
        public function findCandidatesForBrief(DateTimeImmutable $since): array
        {
            return $this->candidates;
        }
    };

    // Conteneur partagé pour capturer les briefs persistés
    $saveBucket = new stdClass();
    $saveBucket->briefs = [];

    $briefRepo = new class($existingBrief, $saveBucket) implements DailyBriefRepositoryInterface {
        public function __construct(
            private readonly ?DailyBrief $existingBrief,
            private readonly stdClass $saveBucket,
        ) {
        }

        public function findForDate(DateTimeImmutable $date): ?DailyBrief
        {
            return $this->existingBrief;
        }

        public function upsertForToday(DailyBrief $brief): void
        {
            $this->saveBucket->briefs[] = $brief;
        }

        /** US-001 : méthode ajoutée à l'interface — retourne null dans ce contexte de test. */
        public function findLatest(): ?DailyBrief
        {
            return null;
        }
    };

    // Conteneur partagé pour capturer les messages de log
    $logBucket = new stdClass();
    $logBucket->messages = [];

    $logger = new class($logBucket) implements LoggerInterface {
        public function __construct(private readonly stdClass $logBucket)
        {
        }

        public function emergency(string|Stringable $message, array $context = []): void
        {
            $this->logBucket->messages[] = ['level' => 'emergency', 'msg' => (string) $message];
        }

        public function alert(string|Stringable $message, array $context = []): void
        {
            $this->logBucket->messages[] = ['level' => 'alert', 'msg' => (string) $message];
        }

        public function critical(string|Stringable $message, array $context = []): void
        {
            $this->logBucket->messages[] = ['level' => 'critical', 'msg' => (string) $message];
        }

        public function error(string|Stringable $message, array $context = []): void
        {
            $this->logBucket->messages[] = ['level' => 'error', 'msg' => (string) $message];
        }

        public function warning(string|Stringable $message, array $context = []): void
        {
            $this->logBucket->messages[] = ['level' => 'warning', 'msg' => (string) $message];
        }

        public function notice(string|Stringable $message, array $context = []): void
        {
            $this->logBucket->messages[] = ['level' => 'notice', 'msg' => (string) $message];
        }

        public function info(string|Stringable $message, array $context = []): void
        {
            $this->logBucket->messages[] = ['level' => 'info', 'msg' => (string) $message];
        }

        public function debug(string|Stringable $message, array $context = []): void
        {
            $this->logBucket->messages[] = ['level' => 'debug', 'msg' => (string) $message];
        }

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->logBucket->messages[] = ['level' => $level, 'msg' => (string) $message];
        }
    };

    $service = new BriefSelectorService($articleRepo, $briefRepo, $logger);

    // Retourne les objets conteneurs (passés par handle en PHP).
    // Les tests accèdent à $saveBucket->briefs et $logBucket->messages APRÈS
    // l'appel au service pour observer les mutations.
    return [$service, $saveBucket, $logBucket, $briefRepo];
}

// ── Scénario nominal : 3 clusters distincts → 3 BriefStories ────────────────

test('Sélection nominale : 3 clusters distincts → 3 BriefStories avec score > 0', function (): void {
    // GIVEN 15 articles de 3 clusters distincts, publiés dans les 24h, is_full_text_accessible=true
    $articles = [];
    foreach (['tech', 'geopolitics', 'economy'] as $cluster) {
        for ($i = 0; $i < 5; ++$i) {
            $articles[] = makeArticle(
                id: "{$cluster}-{$i}",
                sourceId: "source-{$cluster}",
                clusterId: $cluster,
                ageSeconds: $i * 3600,
                contentLength: 1200,
            );
        }
    }

    [$service, $saveBucket] = makeSelectorService($articles);

    // WHEN
    $date = new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC'));
    $event = $service->selectTopStories($date);

    // THEN
    expect($event)->toBeNull('pas d\'événement d\'échec');
    expect($saveBucket->briefs)->toHaveCount(1);

    $brief = $saveBucket->briefs[0];
    expect($brief)->toBeInstanceOf(DailyBrief::class);
    expect($brief->getStatus())->toBe(DailyBriefStatus::Ready);

    $stories = $brief->getStories();
    expect($stories)->toHaveCount(3, 'exactement 3 stories');

    // Chaque story référence un cluster distinct
    $clusters = array_map(static fn ($s) => $s->getSelectionScore(), $stories);
    foreach ($clusters as $score) {
        expect($score)->toBeGreaterThan(0.0, 'score > 0');
    }

    // Positions 1, 2, 3
    $positions = array_map(static fn ($s) => $s->getPosition(), $stories);
    expect($positions)->toBe([1, 2, 3]);
})->group('nominal');

test('Sélection nominale : les 3 BriefStories proviennent de clusters différents', function (): void {
    $articles = [
        makeArticle('tech-1', 'source-1', 'tech', ageSeconds: 100),
        makeArticle('geo-1', 'source-2', 'geopolitics', ageSeconds: 200),
        makeArticle('eco-1', 'source-3', 'economy', ageSeconds: 300),
        makeArticle('tech-2', 'source-1', 'tech', ageSeconds: 400),
        makeArticle('geo-2', 'source-2', 'geopolitics', ageSeconds: 500),
    ];

    [$service, $saveBucket] = makeSelectorService($articles);

    $date = new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC'));
    $service->selectTopStories($date);

    $brief = $saveBucket->briefs[0];
    $articleIds = array_map(static fn ($s) => $s->getArticleId(), $brief->getStories());

    // Les 3 articles sélectionnés proviennent des 3 clusters distincts
    expect($articleIds)->toContain('tech-1')
        ->toContain('geo-1')
        ->toContain('eco-1');
})->group('nominal');

test('Sélection nominale : le DailyBrief est persisté avec updated_at renseigné', function (): void {
    $before = new DateTimeImmutable('2026-07-28 00:00:00', new DateTimeZone('UTC'));

    $articles = [
        makeArticle('a1', 'source-1', 'tech', ageSeconds: 100),
        makeArticle('a2', 'source-2', 'geo', ageSeconds: 200),
        makeArticle('a3', 'source-3', 'eco', ageSeconds: 300),
    ];

    [$service, $saveBucket] = makeSelectorService($articles);

    $date = new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC'));
    $service->selectTopStories($date);

    $brief = $saveBucket->briefs[0];
    expect($brief->getUpdatedAt())->toBeGreaterThanOrEqual($before);
})->group('nominal');

// ── Scénario alternatif 1 : 2 clusters seulement → 2 BriefStories + WARNING ─

test('Alternatif 1 : 2 clusters seulement → 2 BriefStories + log WARNING', function (): void {
    // GIVEN 8 articles couvrant uniquement 2 clusters
    $articles = [];
    for ($i = 0; $i < 4; ++$i) {
        $articles[] = makeArticle("tech-{$i}", 'source-1', 'tech', ageSeconds: $i * 1000);
        $articles[] = makeArticle("geo-{$i}", 'source-2', 'geo', ageSeconds: $i * 1000 + 100);
    }

    [$service, $saveBucket, $logBucket] = makeSelectorService($articles);

    // WHEN
    $date = new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC'));
    $event = $service->selectTopStories($date);

    // THEN
    expect($event)->toBeNull('pas d\'événement d\'échec — brief non bloqué');
    expect($saveBucket->briefs[0]->getStories())->toHaveCount(2, '2 stories exactement');
    expect($saveBucket->briefs[0]->getStatus())->toBe(DailyBriefStatus::Ready, 'status = ready pas error');

    // Un log WARNING doit avoir été émis
    $warnings = array_filter($logBucket->messages, static fn ($m) => 'warning' === $m['level']);
    expect($warnings)->not->toBeEmpty('log WARNING émis');

    $warningMessages = array_values($warnings);
    expect($warningMessages[0]['msg'])->toContain('brief.incomplete');
})->group('alternatif');

// ── Scénario alternatif 2 : re-exécution idempotente ─────────────────────────

test('Alternatif 2 : re-exécution le même jour → update pas doublon', function (): void {
    // GIVEN un brief existant pour la date
    $existingBrief = new DailyBrief(
        id: 'existing-brief-id',
        date: new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC')),
        status: DailyBriefStatus::Ready,
        updatedAt: new DateTimeImmutable('2026-07-28 06:00:00', new DateTimeZone('UTC')),
    );

    $articles = [
        makeArticle('a1', 'source-1', 'tech', ageSeconds: 100),
        makeArticle('a2', 'source-2', 'geo', ageSeconds: 200),
        makeArticle('a3', 'source-3', 'eco', ageSeconds: 300),
    ];

    [$service, $saveBucket] = makeSelectorService($articles, existingBrief: $existingBrief);

    // WHEN (re-run)
    $date = new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC'));
    $event = $service->selectTopStories($date);

    // THEN
    expect($event)->toBeNull();
    // upsertForToday appelé exactement une fois (pas de doublon INSERT)
    expect($saveBucket->briefs)->toHaveCount(1);

    $updatedBrief = $saveBucket->briefs[0];
    // Le brief existant est conservé (même objet, updated_at rafraîchi)
    expect($updatedBrief->getId())->toBe('existing-brief-id', 'même ID — pas de nouveau brief');
    expect($updatedBrief->getUpdatedAt())->toBeGreaterThan(
        new DateTimeImmutable('2026-07-28 06:00:00', new DateTimeZone('UTC')),
        'updated_at rafraîchi',
    );
    expect($updatedBrief->getStories())->toHaveCount(3, 'stories remplacées');
})->group('alternatif');

// ── Scénario erreur 1 : 0 articles disponibles ───────────────────────────────

test('Erreur : 0 articles disponibles → BriefGenerationFailedEvent retourné + log ERROR', function (): void {
    [$service, $saveBucket, $logBucket] = makeSelectorService(candidates: []);

    $date = new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC'));
    $event = $service->selectTopStories($date);

    // THEN : événement d'échec retourné
    expect($event)->toBeInstanceOf(BriefGenerationFailedEvent::class);
    expect($event->reason)->toBe('no_articles_available');
    expect($event->targetDate->format('Y-m-d'))->toBe('2026-07-28');

    // Aucun brief persisté
    expect($saveBucket->briefs)->toBeEmpty('aucun brief persisté quand 0 articles');

    // Log ERROR émis
    $errors = array_filter($logBucket->messages, static fn ($m) => 'error' === $m['level']);
    expect($errors)->not->toBeEmpty('log ERROR émis');
})->group('erreur');

// ── Tests du scoring ─────────────────────────────────────────────────────────

test('Scoring : article récent obtient un meilleur score de fraîcheur que article ancien', function (): void {
    // Deux articles dans des sources différentes (pour contourner la règle de diversité Sprint 1)
    $recent = makeArticle('recent', 'source-1', clusterId: null, ageSeconds: 100);
    $old = makeArticle('old', 'source-2', clusterId: null, ageSeconds: 80_000);

    [$service, $saveBucket] = makeSelectorService([$recent, $old]);

    $date = new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC'));
    $service->selectTopStories($date);

    $stories = $saveBucket->briefs[0]->getStories();
    // L'article récent doit être en position 1 (score fraîcheur supérieur)
    $story1 = array_values(array_filter($stories, static fn ($s) => 1 === $s->getPosition()))[0];
    expect($story1->getArticleId())->toBe('recent');
})->group('scoring');

test('Scoring : article long (>800 chars) obtient le bonus engagement', function (): void {
    // Article long vs article court, même âge, sources différentes
    $long = makeArticle('long', 'source-1', null, ageSeconds: 100, contentLength: 1000);
    $short = makeArticle('short', 'source-2', null, ageSeconds: 100, contentLength: 200);

    [$service, $saveBucket] = makeSelectorService([$long, $short]);

    $date = new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC'));
    $service->selectTopStories($date);

    $stories = $saveBucket->briefs[0]->getStories();
    $story1 = array_values(array_filter($stories, static fn ($s) => 1 === $s->getPosition()))[0];
    expect($story1->getArticleId())->toBe('long', 'article long sélectionné en premier');
})->group('scoring');

// ── Tests de BriefStory (Value Object) ───────────────────────────────────────

test('BriefStory rejette une position hors [1, 3]', function (): void {
    expect(static fn () => new App\Domain\Brief\BriefStory('id', 'brief', 'art', 0, 10.0))
        ->toThrow(InvalidArgumentException::class);

    expect(static fn () => new App\Domain\Brief\BriefStory('id', 'brief', 'art', 4, 10.0))
        ->toThrow(InvalidArgumentException::class);
})->group('value-object');

test('BriefStory rejette un score négatif', function (): void {
    expect(static fn () => new App\Domain\Brief\BriefStory('id', 'brief', 'art', 1, -1.0))
        ->toThrow(InvalidArgumentException::class);
})->group('value-object');

test('BriefStory expose correctement ses propriétés', function (): void {
    $story = new App\Domain\Brief\BriefStory('story-id', 'brief-id', 'art-id', 2, 75.5);

    expect($story->getId())->toBe('story-id')
        ->and($story->getBriefId())->toBe('brief-id')
        ->and($story->getArticleId())->toBe('art-id')
        ->and($story->getPosition())->toBe(2)
        ->and($story->getSelectionScore())->toBe(75.5);
})->group('value-object');

// ── Tests de DailyBrief ───────────────────────────────────────────────────────

test('DailyBrief::applySelection() passe le statut à Ready', function (): void {
    $brief = new DailyBrief(
        'id-1',
        new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC')),
        DailyBriefStatus::Pending,
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
    );

    $story = new App\Domain\Brief\BriefStory('s1', 'id-1', 'art-1', 1, 50.0);
    $brief->applySelection([$story], new DateTimeImmutable('now', new DateTimeZone('UTC')));

    expect($brief->getStatus())->toBe(DailyBriefStatus::Ready)
        ->and($brief->isReady())->toBeTrue()
        ->and($brief->getStories())->toHaveCount(1);
})->group('entity');

test('DailyBrief::markError() passe le statut à Error', function (): void {
    $brief = new DailyBrief(
        'id-2',
        new DateTimeImmutable('2026-07-28', new DateTimeZone('UTC')),
        DailyBriefStatus::Pending,
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
    );

    $brief->markError(new DateTimeImmutable('now', new DateTimeZone('UTC')));

    expect($brief->getStatus())->toBe(DailyBriefStatus::Error)
        ->and($brief->isReady())->toBeFalse();
})->group('entity');

// ── Tests de GenerateDailyBriefMessage ───────────────────────────────────────

test('GenerateDailyBriefMessage accepte un format Y-m-d valide', function (): void {
    $msg = new App\Application\Brief\GenerateDailyBrief\GenerateDailyBriefMessage('2026-07-28');

    expect($msg->dateTarget)->toBe('2026-07-28')
        ->and($msg->getDate()->format('Y-m-d'))->toBe('2026-07-28');
})->group('message');

test('GenerateDailyBriefMessage rejette un format invalide', function (): void {
    expect(static fn () => new App\Application\Brief\GenerateDailyBrief\GenerateDailyBriefMessage('28-07-2026'))
        ->toThrow(InvalidArgumentException::class);

    expect(static fn () => new App\Application\Brief\GenerateDailyBrief\GenerateDailyBriefMessage('not-a-date'))
        ->toThrow(InvalidArgumentException::class);
})->group('message');
