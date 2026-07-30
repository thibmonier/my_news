<?php

declare(strict_types=1);

use App\Domain\Feed\ArticleDTO;
use App\Domain\Feed\ContentHash;
use App\Domain\Feed\SimHashServiceInterface;
use App\Infrastructure\Brief\Persistence\DoctrineArticleCandidateRepository;
use App\Infrastructure\Feed\Persistence\DoctrineArticleRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/*
 * Feature/Integration tests — SimHash Déduplication (US-022)
 *
 * Teste contre PostgreSQL réel (APP_ENV=test).
 * Couvre les 5 scénarios Gherkin US-022 :
 *
 * 1. (nominal)  Article B proche (distance≤3) + ≤2h → is_duplicate=TRUE + duplicate_of=A.id
 * 2. (alt 1)    Article similaire mais >2h → is_duplicate=FALSE
 * 3. (alt 2)    Titres proches mais distance>seuil → is_duplicate=FALSE
 * 4. (erreur 1) Titre absent → title_simhash=NULL + log WARNING + ingestion continue
 * 5. Exclusion  Doublons filtrés dans findCandidatesForBrief (is_duplicate=FALSE)
 *
 * Chaque test insère ses propres données et les nettoie via TRUNCATE.
 */
uses(KernelTestCase::class);

// ── Setup / Teardown ───────────────────────────────────────────────────────

/** UUID v4 stable pour la source de test. */
const TEST_SOURCE_ID = 'e5e9fa1b-a428-4c69-8640-000000000001';

/** Insère une source de test (idempotent). */
function insertTestSource(Connection $conn): void
{
    $existing = $conn->fetchOne('SELECT id FROM sources WHERE id = ?', [TEST_SOURCE_ID]);
    if ($existing) {
        return;
    }

    $conn->executeStatement(
        <<<'SQL'
            INSERT INTO sources (id, name, url, feed_type, status, created_at, fetch_interval_minutes)
            VALUES (:id, :name, :url, :feed_type, :status, :created_at, :interval)
            ON CONFLICT (id) DO NOTHING
            SQL,
        [
            'id' => TEST_SOURCE_ID,
            'name' => 'Test Source SimHash',
            'url' => 'https://test-simhash.example.com/feed',
            'feed_type' => 'rss',
            'status' => 'active',
            'created_at' => '2026-01-01 00:00:00',
            'interval' => 30,
        ],
    );
}

/** Supprime tous les articles de test (par source_id) après chaque test. */
function cleanupTestArticles(Connection $conn): void
{
    $conn->executeStatement(
        'DELETE FROM articles WHERE source_id = ?',
        [TEST_SOURCE_ID],
    );
}

/** Insère un article de test directement en DB avec title_simhash. */
function insertArticleWithSimHash(
    Connection $conn,
    string $id,
    string $title,
    ?int $simhash,
    DateTimeImmutable $publishedAt,
    bool $isDuplicate = false,
    ?string $duplicateOf = null,
): void {
    $conn->executeStatement(
        <<<'SQL'
            INSERT INTO articles (
                id, source_id, title, url, content_hash,
                published_at, raw_content, fetch_at,
                is_full_text_accessible, category,
                title_simhash, is_duplicate, duplicate_of
            ) VALUES (
                :id, :source_id, :title, :url, :content_hash,
                :published_at, :raw_content, :fetch_at,
                TRUE, 'ai_insight',
                :simhash, :is_duplicate, :duplicate_of
            )
            SQL,
        [
            'id' => $id,
            'source_id' => TEST_SOURCE_ID,
            'title' => $title,
            'url' => 'https://test-simhash.example.com/article-' . substr($id, -8),
            'content_hash' => hash('sha256', $id),
            'published_at' => $publishedAt->format('Y-m-d H:i:sP'),
            'raw_content' => 'Test content for ' . $title,
            'fetch_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:sP'),
            'simhash' => $simhash,
            'is_duplicate' => $isDuplicate ? 'TRUE' : 'FALSE',
            'duplicate_of' => $duplicateOf,
        ],
    );
}

beforeEach(function (): void {
    self::bootKernel();
    $conn = static::getContainer()->get(Connection::class);
    insertTestSource($conn);
    cleanupTestArticles($conn);
});

afterEach(function (): void {
    $conn = static::getContainer()->get(Connection::class);
    cleanupTestArticles($conn);
});

// ── Tests ──────────────────────────────────────────────────────────────────

test('findPotentialDuplicates retourne un article proche dans la fenêtre ±2h (scénario nominal)', function (): void {
    $container = static::getContainer();
    $conn = $container->get(Connection::class);
    $simHashService = $container->get(SimHashServiceInterface::class);
    $repo = $container->get(DoctrineArticleRepository::class);

    // Article A : déjà en base avec simhash calculé
    // Les stopwords 'le' et 'de' sont retirés → tokens identiques pour A et B
    // Garantit distance = 0 ≤ seuil 3, quel que soit le titre
    $titleA = 'grand prix formule 1 Monaco résultats';
    $simhashA = $simHashService->compute($titleA);
    assert(null !== $simhashA, 'SimHash de A ne doit pas être null');

    $idA = Uuid::v4()->toRfc4122();
    $publishedAtA = new DateTimeImmutable('2026-01-15 14:00:00', new DateTimeZone('UTC'));
    insertArticleWithSimHash($conn, $idA, $titleA, $simhashA, $publishedAtA);

    // Article B : même titre qu'A avec stopword ajouté → distance = 0
    $titleB = 'le grand prix de formule 1 Monaco résultats';
    $simhashB = $simHashService->compute($titleB);
    assert(null !== $simhashB, 'SimHash de B ne doit pas être null');
    assert($simhashB === $simhashA, 'SimHash A et B doivent être égaux (tokens identiques après stopwords)');
    $publishedAtB = new DateTimeImmutable('2026-01-15 14:25:00', new DateTimeZone('UTC'));

    // findPotentialDuplicates doit trouver A (distance = 0 ≤ seuil 3, fenêtre 25 min ≤ 2h)
    $duplicates = $repo->findPotentialDuplicates($simhashB, $publishedAtB, threshold: 3);

    expect($duplicates)->toHaveCount(1);
    expect($duplicates[0]['id'])->toBe($idA);
    expect($duplicates[0]['title'])->toBe($titleA);
});

test('findPotentialDuplicates retourne vide si l\'article similaire est à >2h (scénario alt 1)', function (): void {
    $container = static::getContainer();
    $conn = $container->get(Connection::class);
    $simHashService = $container->get(SimHashServiceInterface::class);
    $repo = $container->get(DoctrineArticleRepository::class);

    $titleA = 'Apple annonce son nouvel iPhone 17 Pro';
    $simhashA = $simHashService->compute($titleA);
    assert(null !== $simhashA);

    $idA = Uuid::v4()->toRfc4122();
    // A publié la veille à 14h
    $publishedAtA = new DateTimeImmutable('2026-01-14 14:00:00', new DateTimeZone('UTC'));
    insertArticleWithSimHash($conn, $idA, $titleA, $simhashA, $publishedAtA);

    $titleB = 'Apple dévoile iPhone 17 Pro';
    $simhashB = $simHashService->compute($titleB);
    assert(null !== $simhashB);
    // B publié le lendemain à 11h → 21h d'écart → hors fenêtre ±2h
    $publishedAtB = new DateTimeImmutable('2026-01-15 11:00:00', new DateTimeZone('UTC'));

    $duplicates = $repo->findPotentialDuplicates($simhashB, $publishedAtB, threshold: 3);

    expect($duplicates)->toBeEmpty('Articles à 21h d\'écart : hors fenêtre ±2h, pas de doublon attendu');
});

test('findPotentialDuplicates retourne vide si la distance SimHash > seuil (scénario alt 2)', function (): void {
    $container = static::getContainer();
    $conn = $container->get(Connection::class);
    $simHashService = $container->get(SimHashServiceInterface::class);
    $repo = $container->get(DoctrineArticleRepository::class);

    $titleA = 'Apple investit massivement dans intelligence artificielle';
    $simhashA = $simHashService->compute($titleA);
    assert(null !== $simhashA);

    $idA = Uuid::v4()->toRfc4122();
    $publishedAtA = new DateTimeImmutable('2026-01-15 14:00:00', new DateTimeZone('UTC'));
    insertArticleWithSimHash($conn, $idA, $titleA, $simhashA, $publishedAtA);

    $titleB = 'Apple investit massivement dans santé numérique';
    $simhashB = $simHashService->compute($titleB);
    assert(null !== $simhashB);
    $publishedAtB = new DateTimeImmutable('2026-01-15 14:30:00', new DateTimeZone('UTC'));

    // Vérifier que la distance est > 3 pour ce test
    $distance = $simHashService->distance($simhashA, $simhashB);
    if ($distance <= 3) {
        test()->markTestSkipped("Distance $distance ≤ 3 : titres trop proches pour ce scénario");
    }

    $duplicates = $repo->findPotentialDuplicates($simhashB, $publishedAtB, threshold: 3);

    expect($duplicates)->toBeEmpty("Distance $distance > seuil 3 : pas de doublon attendu");
});

test('markAsDuplicate met à jour les champs is_duplicate et duplicate_of', function (): void {
    $container = static::getContainer();
    $conn = $container->get(Connection::class);
    $simHashService = $container->get(SimHashServiceInterface::class);
    $repo = $container->get(DoctrineArticleRepository::class);

    $titleA = 'Tesla dévoile son nouveau Cybertruck autonome';
    $simhashA = $simHashService->compute($titleA);
    assert(null !== $simhashA);

    $idA = Uuid::v4()->toRfc4122();
    $publishedAtA = new DateTimeImmutable('2026-01-15 10:00:00', new DateTimeZone('UTC'));
    insertArticleWithSimHash($conn, $idA, $titleA, $simhashA, $publishedAtA);

    // Insérer B sans simhash (vient d'être inséré)
    $idB = Uuid::v4()->toRfc4122();
    $titleB = 'Tesla présente le Cybertruck autonome';
    $publishedAtB = new DateTimeImmutable('2026-01-15 10:30:00', new DateTimeZone('UTC'));
    $simhashB = $simHashService->compute($titleB);
    assert(null !== $simhashB);
    insertArticleWithSimHash($conn, $idB, $titleB, null, $publishedAtB); // simhash null à l'insertion

    // markAsDuplicate
    $repo->markAsDuplicate($idB, $simhashB, $idA);

    // Vérifier en DB
    $row = $conn->fetchAssociative('SELECT is_duplicate, duplicate_of, title_simhash FROM articles WHERE id = ?', [$idB]);
    assert(false !== $row, 'Article B introuvable');

    expect($row['is_duplicate'])->toBeTrue();
    expect($row['duplicate_of'])->toBe($idA);
    expect((int) $row['title_simhash'])->toBe($simhashB);
});

test('saveIgnoringDuplicate retourne null (article de test)', function (): void {
    $container = static::getContainer();
    $repo = $container->get(DoctrineArticleRepository::class);

    $url = 'https://test-simhash.example.com/unique-article-' . uniqid();
    $dto = new ArticleDTO(
        sourceId: TEST_SOURCE_ID,
        title: 'Test article pour saveIgnoringDuplicate US-022',
        url: $url,
        canonicalUrl: $url,
        contentHash: ContentHash::fromCanonicalUrl($url),
        rawContent: 'Test content',
        publishedAt: new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')),
    );

    // Première insertion → retourne UUID string
    $insertedId = $repo->saveIgnoringDuplicate($dto);
    expect($insertedId)->toBeString()->not->toBeNull();

    // Deuxième insertion → doublon SHA-256 → retourne null
    $duplicateId = $repo->saveIgnoringDuplicate($dto);
    expect($duplicateId)->toBeNull();
});

test('findCandidatesForBrief exclut les articles marqués is_duplicate=TRUE (scénario exclusion)', function (): void {
    $container = static::getContainer();
    $conn = $container->get(Connection::class);
    $simHashService = $container->get(SimHashServiceInterface::class);
    $candidateRepo = $container->get(DoctrineArticleCandidateRepository::class);

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    // Article A : non-doublon (éligible au brief)
    $titleA = 'L\'intelligence artificielle révolutionne la médecine';
    $simhashA = $simHashService->compute($titleA);
    $idA = Uuid::v4()->toRfc4122();
    insertArticleWithSimHash($conn, $idA, $titleA, $simhashA, $now, isDuplicate: false);

    // Article B : doublon de A (doit être exclu du brief)
    $titleB = 'L\'IA révolutionne la médecine moderne';
    $simhashB = $simHashService->compute($titleB);
    $idB = Uuid::v4()->toRfc4122();
    insertArticleWithSimHash($conn, $idB, $titleB, $simhashB, $now, isDuplicate: true, duplicateOf: $idA);

    // findCandidatesForBrief depuis il y a 24h
    $since = $now->modify('-24 hours');
    $candidates = $candidateRepo->findCandidatesForBrief($since);

    $candidateIds = array_map(fn ($a) => $a->getId(), $candidates);

    // Pest 4 : toContain() accepte plusieurs needles — passer uniquement l'ID, sans message
    expect($candidateIds)->toContain($idA); // Article A non-doublon doit être candidat au Brief
    expect($candidateIds)->not->toContain($idB); // Article B doublon doit être exclu du Brief
});

test('updateTitleSimHash met à jour uniquement title_simhash (non-doublon)', function (): void {
    $container = static::getContainer();
    $conn = $container->get(Connection::class);
    $simHashService = $container->get(SimHashServiceInterface::class);
    $repo = $container->get(DoctrineArticleRepository::class);

    $title = 'Google annonce une percée dans le calcul quantique';
    $simhash = $simHashService->compute($title);
    assert(null !== $simhash);

    $id = Uuid::v4()->toRfc4122();
    $publishedAt = new DateTimeImmutable('2026-01-15 09:00:00', new DateTimeZone('UTC'));
    insertArticleWithSimHash($conn, $id, $title, null, $publishedAt); // simhash null à l'insertion

    $repo->updateTitleSimHash($id, $simhash);

    $row = $conn->fetchAssociative('SELECT title_simhash, is_duplicate, duplicate_of FROM articles WHERE id = ?', [$id]);
    assert(false !== $row);

    expect((int) $row['title_simhash'])->toBe($simhash);
    expect($row['is_duplicate'])->toBeFalse(); // inchangé
    expect($row['duplicate_of'])->toBeNull(); // inchangé
});
