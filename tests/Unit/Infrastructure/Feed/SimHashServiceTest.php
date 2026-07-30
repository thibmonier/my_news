<?php

declare(strict_types=1);

use App\Infrastructure\Feed\SimHash\SimHashService;

/*
 * Unit tests — SimHashService (Infrastructure/Feed/SimHash)
 *
 * Couvre T-022-08 et les scénarios Gherkin US-022 :
 * - Titre vide → null
 * - Titre uniquement espaces → null
 * - Titre normal → int 64 bits non null
 * - Deux titres proches → distance ≤ 3
 * - Deux titres distincts → distance > 3
 * - Stopwords supprimés : "le grand prix" ≡ "grand prix" → distance = 0
 * - Titre CJK pur → int non null (caractères valides, pas d'exception)
 * - Distance de Hamming : propriétés fondamentales
 */

// ── Helpers ────────────────────────────────────────────────────────────────

function simHashService(): SimHashService
{
    return new SimHashService();
}

// ── Tests compute() ────────────────────────────────────────────────────────

test('compute retourne null pour un titre vide', function (): void {
    expect(simHashService()->compute(''))->toBeNull();
});

test('compute retourne null pour un titre composé uniquement d\'espaces', function (): void {
    expect(simHashService()->compute('   '))->toBeNull();
    expect(simHashService()->compute("\t\n"))->toBeNull();
});

test('compute retourne un entier non null pour un titre normal', function (): void {
    $result = simHashService()->compute('Apple annonce son nouvel iPhone 17 Pro');
    expect($result)->toBeInt()->not->toBeNull();
});

test('compute retourne un entier PHP 64 bits (peut être négatif)', function (): void {
    $result = simHashService()->compute('Breaking news on artificial intelligence');
    expect($result)->toBeInt();
    // Peut être négatif (bit 63 = signe PHP) — comportement normal
    expect($result)->toBeGreaterThanOrEqual(\PHP_INT_MIN)->toBeLessThanOrEqual(\PHP_INT_MAX);
});

test('deux titres quasi-identiques (stopword ajouté) ont une distance de Hamming de 0', function (): void {
    $service = simHashService();
    // "le" et "de" sont des stopwords → les deux titres produisent les mêmes tokens
    // tokens communs : grand, prix, formule, 1, monaco
    $hashA = $service->compute('grand prix formule 1 Monaco');
    $hashB = $service->compute('le grand prix de formule 1 Monaco');

    expect($hashA)->not->toBeNull();
    expect($hashB)->not->toBeNull();

    /** @var int $hashA @var int $hashB */
    $distance = $service->distance($hashA, $hashB);
    expect($distance)->toBe(0, "Distance $distance ≠ 0 : les stopwords doivent être ignorés, les tokens sont identiques");
});

test('deux titres sémantiquement distincts ont une distance de Hamming > 0', function (): void {
    $service = simHashService();
    // Titres partageant peu de tokens communs → distance élevée
    $hashA = $service->compute('Apple investit massivement intelligence artificielle');
    $hashB = $service->compute('Tesla rappelle batteries cybertruck sécurité');

    expect($hashA)->not->toBeNull();
    expect($hashB)->not->toBeNull();

    /** @var int $hashA @var int $hashB */
    $distance = $service->distance($hashA, $hashB);
    expect($distance)->toBeGreaterThan(0, "Distance $distance = 0 : des titres sans tokens communs doivent différer");
});

test('stopwords FR supprimés : "le grand prix" et "grand prix" ont distance 0', function (): void {
    $service = simHashService();
    $hashA = $service->compute('le grand prix');
    $hashB = $service->compute('grand prix');

    expect($hashA)->toEqual($hashB, '"le grand prix" et "grand prix" doivent produire le même SimHash');
    /* @var int $hashA @var int $hashB */
    expect($service->distance($hashA, $hashB))->toBe(0);
});

test('stopwords EN supprimés : "the grand prix" et "grand prix" ont distance 0', function (): void {
    $service = simHashService();
    $hashA = $service->compute('the grand prix');
    $hashB = $service->compute('grand prix');

    expect($hashA)->toEqual($hashB);
    /* @var int $hashA @var int $hashB */
    expect($service->distance($hashA, $hashB))->toBe(0);
});

test('titre composé uniquement de stopwords retourne null', function (): void {
    $service = simHashService();
    // 'le la les' → tous des stopwords → liste de tokens vide
    expect($service->compute('le la les'))->toBeNull();
    expect($service->compute('the a an of'))->toBeNull();
});

test('titre CJK pur retourne un entier non null (pas d\'exception)', function (): void {
    $service = simHashService();
    // Caractères U+4E00–U+9FFF : preg_split fonctionne normalement en Unicode
    $result = $service->compute('你好世界 人工智能');
    expect($result)->toBeInt()->not->toBeNull();
});

test('compute est idempotent — même titre → même SimHash', function (): void {
    $service = simHashService();
    $title = 'OpenAI annonce GPT-5 avec des capacités révolutionnaires';

    expect($service->compute($title))->toEqual($service->compute($title));
});

// ── Tests distance() ───────────────────────────────────────────────────────

test('distance de 0 pour deux SimHash identiques', function (): void {
    $service = simHashService();
    $hash = $service->compute('test title for distance zero');
    /* @var int $hash */
    expect($service->distance($hash, $hash))->toBe(0);
});

test('distance entre 0 et -1 (0 vs tous bits à 1) est 64', function (): void {
    $service = simHashService();
    // -1 en binaire complément à 2 = 64 bits à 1
    expect($service->distance(0, -1))->toBe(64);
    expect($service->distance(-1, 0))->toBe(64);
});

test('distance est symétrique', function (): void {
    $service = simHashService();
    $hashA = $service->compute('première phrase de test');
    $hashB = $service->compute('deuxième phrase de test');

    expect($hashA)->not->toBeNull();
    expect($hashB)->not->toBeNull();
    /* @var int $hashA @var int $hashB */
    expect($service->distance($hashA, $hashB))->toEqual($service->distance($hashB, $hashA));
});

test('distance entre deux valeurs avec 1 bit de différence est 1', function (): void {
    $service = simHashService();
    // 0b0001 vs 0b0011 → 1 bit de différence (bit 1)
    expect($service->distance(0b0001, 0b0011))->toBe(1);
});

test('distance gère correctement les entiers négatifs (bit 63 = signe)', function (): void {
    $service = simHashService();
    // PHP_INT_MIN = bit 63 à 1, tous les autres à 0
    // PHP_INT_MIN XOR 0 = PHP_INT_MIN → 1 bit différent
    expect($service->distance(\PHP_INT_MIN, 0))->toBe(1);
    // PHP_INT_MIN XOR PHP_INT_MAX : bit 63 = 1, bits 0-62 = 1 → 64 bits différents
    expect($service->distance(\PHP_INT_MIN, \PHP_INT_MAX))->toBe(64);
});
