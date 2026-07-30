<?php

declare(strict_types=1);

use App\Application\Synthesis\UrlNormalizer;
use App\Domain\Synthesis\InvalidSynthesisUrlException;

/*
 * Unit tests — UrlNormalizer (US-012 T-012-01)
 *
 * Couvre les scénarios Gherkin US-012 :
 *   - Canonicalisation : lowercase scheme+host, tri query params, strip fragment
 *   - URLs équivalentes → même URL normalisée → même clé cache
 *   - Caractères de contrôle (\r, \n, \0) → InvalidSynthesisUrlException (HTTP 422)
 *   - URL invalide après normalisation → InvalidSynthesisUrlException
 *   - URL déjà normalisée → inchangée (idempotence)
 */

function makeNormalizer(): UrlNormalizer
{
    return new UrlNormalizer();
}

// ── Lowercase scheme et host ───────────────────────────────────────────────────

test('normalize convertit le schéma en minuscules', function (): void {
    $normalizer = makeNormalizer();
    $result = $normalizer->normalize('HTTPS://example.com/article');
    expect($result)->toStartWith('https://');
});

test('normalize convertit le host en minuscules', function (): void {
    $normalizer = makeNormalizer();
    $result = $normalizer->normalize('https://TechCrunch.COM/article');
    expect($result)->toContain('techcrunch.com');
});

test('normalize lowercase scheme ET host ensemble', function (): void {
    $normalizer = makeNormalizer();
    $result = $normalizer->normalize('HTTPS://TechCrunch.COM/article');
    expect($result)->toBe('https://techcrunch.com/article');
});

// ── Tri alphabétique des query params ─────────────────────────────────────────

test('normalize trie les query params alphabétiquement', function (): void {
    $normalizer = makeNormalizer();
    $result = $normalizer->normalize('https://example.com/article?z=1&a=2');
    expect($result)->toBe('https://example.com/article?a=2&z=1');
});

test('normalize avec query params déjà triés → résultat identique', function (): void {
    $normalizer = makeNormalizer();
    $url = 'https://example.com/article?a=1&z=3';
    $result = $normalizer->normalize($url);
    expect($result)->toBe('https://example.com/article?a=1&z=3');
});

test('normalize trie 3 paramètres dans l\'ordre alphabétique', function (): void {
    $normalizer = makeNormalizer();
    $result = $normalizer->normalize('https://example.com/search?q=ai&page=2&lang=fr');
    // Ordre alphabétique : lang, page, q
    expect($result)->toBe('https://example.com/search?lang=fr&page=2&q=ai');
});

// ── Suppression du fragment ────────────────────────────────────────────────────

test('normalize supprime le fragment (#section)', function (): void {
    $normalizer = makeNormalizer();
    $result = $normalizer->normalize('https://example.com/article#section-1');
    expect($result)->toBe('https://example.com/article');
    expect($result)->not->toContain('#');
});

test('normalize supprime le fragment même avec query params', function (): void {
    $normalizer = makeNormalizer();
    $result = $normalizer->normalize('https://example.com/article?a=1#footer');
    expect($result)->not->toContain('#');
    expect($result)->toContain('?a=1');
});

// ── Canonicalisation complète (scénario principal US-012) ─────────────────────

test('normalize URLs équivalentes → même URL normalisée (canonicalisation complète)', function (): void {
    $normalizer = makeNormalizer();

    $url1 = 'HTTPS://TechCrunch.COM/article?z=1&a=2';
    $url2 = 'https://techcrunch.com/article?a=2&z=1';

    expect($normalizer->normalize($url1))->toBe($normalizer->normalize($url2));
});

test('normalize URL déjà normalisée → idempotente', function (): void {
    $normalizer = makeNormalizer();
    $url = 'https://example.com/article?a=1&z=3';
    expect($normalizer->normalize($url))->toBe($url);
});

test('normalize URLs avec fragment différent → même URL normalisée', function (): void {
    $normalizer = makeNormalizer();
    $url1 = 'https://example.com/article#intro';
    $url2 = 'https://example.com/article#conclusion';
    expect($normalizer->normalize($url1))->toBe($normalizer->normalize($url2));
});

// ── Assainissement contre injection de clé (anti key-injection Redis) ──────────

test('normalize lève InvalidSynthesisUrlException si URL contient \\r (CR)', function (): void {
    $normalizer = makeNormalizer();

    expect(static fn () => $normalizer->normalize("https://example.com/article\r\nX-Injected: header"))
        ->toThrow(InvalidSynthesisUrlException::class);
});

test('normalize lève InvalidSynthesisUrlException si URL contient \\n (LF)', function (): void {
    $normalizer = makeNormalizer();

    expect(static fn () => $normalizer->normalize("https://example.com/article\ninjection"))
        ->toThrow(InvalidSynthesisUrlException::class);
});

test('normalize lève InvalidSynthesisUrlException si URL contient \\0 (null byte)', function (): void {
    $normalizer = makeNormalizer();

    expect(static fn () => $normalizer->normalize("https://example.com/article\0hidden"))
        ->toThrow(InvalidSynthesisUrlException::class);
});

// ── URL invalides après normalisation ─────────────────────────────────────────

test('normalize lève InvalidSynthesisUrlException pour URL sans schéma', function (): void {
    $normalizer = makeNormalizer();

    expect(static fn () => $normalizer->normalize('example.com/article'))
        ->toThrow(InvalidSynthesisUrlException::class);
});

test('normalize lève InvalidSynthesisUrlException pour URL vide', function (): void {
    $normalizer = makeNormalizer();

    expect(static fn () => $normalizer->normalize(''))
        ->toThrow(InvalidSynthesisUrlException::class);
});

// ── Port dans l'URL ───────────────────────────────────────────────────────────

test('normalize préserve le port dans l\'URL', function (): void {
    $normalizer = makeNormalizer();
    $result = $normalizer->normalize('https://example.com:8443/api');
    expect($result)->toContain(':8443');
});

// ── URLs sans query params (cas le plus courant) ──────────────────────────────

test('normalize URL sans query params → pas de ? dans le résultat', function (): void {
    $normalizer = makeNormalizer();
    $result = $normalizer->normalize('https://example.com/article');
    expect($result)->not->toContain('?');
    expect($result)->toBe('https://example.com/article');
});
