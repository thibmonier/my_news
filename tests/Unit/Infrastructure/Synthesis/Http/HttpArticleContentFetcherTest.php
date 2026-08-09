<?php

declare(strict_types=1);

use App\Domain\Synthesis\SynthesisUnavailableException;
use App\Infrastructure\Synthesis\Http\HttpArticleContentFetcher;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/*
 * Unit tests — HttpArticleContentFetcher (anti-SSRF / DNS rebinding)
 *
 * Vérifie la défense en profondeur : le host est résolu + validé avant tout
 * appel HTTP, et un host pointant vers une IP interne est rejeté sans requête.
 */

test('fetchContent rejette une IP privée littérale sans appel HTTP', function (): void {
    // Le client ne doit jamais être appelé : exception levée à la validation.
    $client = new MockHttpClient(function (): MockResponse {
        throw new RuntimeException('HTTP client ne doit pas être appelé pour une IP bloquée');
    });
    $fetcher = new HttpArticleContentFetcher($client, new NullLogger());

    expect(static fn () => $fetcher->fetchContent('http://10.0.0.1/article'))
        ->toThrow(SynthesisUnavailableException::class);
});

test('fetchContent rejette le loopback littéral', function (): void {
    $client = new MockHttpClient(fn (): MockResponse => new MockResponse('should not reach'));
    $fetcher = new HttpArticleContentFetcher($client, new NullLogger());

    expect(static fn () => $fetcher->fetchContent('http://127.0.0.1/x'))
        ->toThrow(SynthesisUnavailableException::class);
});

test('fetchContent rejette un host sans résolution possible', function (): void {
    $client = new MockHttpClient(fn (): MockResponse => new MockResponse('should not reach'));
    $fetcher = new HttpArticleContentFetcher($client, new NullLogger());

    expect(static fn () => $fetcher->fetchContent('http://nxdomain.invalid/x'))
        ->toThrow(SynthesisUnavailableException::class);
});

test('fetchContent autorise une IP publique littérale et retourne le texte', function (): void {
    $html = '<html><body>' . str_repeat('Contenu article public suffisamment long. ', 30) . '</body></html>';
    $client = new MockHttpClient(new MockResponse($html, ['http_code' => 200]));
    $fetcher = new HttpArticleContentFetcher($client, new NullLogger());

    $result = $fetcher->fetchContent('http://93.184.216.34/article');

    expect($result->text)->toContain('Contenu article public');
    expect($result->isPartial)->toBeFalse();
});
