<?php

declare(strict_types=1);

use App\Domain\Synthesis\SynthesisUnavailableException;
use App\Infrastructure\Synthesis\Ai\MistralApiClient;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/*
 * Unit tests — MistralApiClient (Infrastructure/Synthesis/Ai)
 *
 * Couvre T-010-11 :
 *   - Parsing réponse JSON correcte → retourne le contenu textuel
 *   - Timeout → SynthesisUnavailableException
 *   - HTTP 5xx → SynthesisUnavailableException
 *   - Réponse vide → SynthesisUnavailableException
 *   - PII-free assertion : aucun email ni UUID user dans le prompt envoyé
 *
 * Utilise MockHttpClient (Symfony HttpClient) — aucun appel réseau réel.
 */

const MISTRAL_SYSTEM = 'System prompt for testing';
const MISTRAL_CONTENT = 'Article content for testing';
const MISTRAL_FAKE_KEY = 'test-api-key-not-real';

/**
 * Construit un MistralApiClient avec un MockHttpClient.
 */
function makeMistralClient(MockHttpClient $http): MistralApiClient
{
    return new MistralApiClient(
        httpClient: $http,
        apiKey: MISTRAL_FAKE_KEY,
        logger: new NullLogger(),
    );
}

// ── Parsing réponse JSON correcte ─────────────────────────────────────────────

test('complete retourne le contenu textuel de la réponse Mistral', function (): void {
    $expectedContent = 'BRIEFLY AI: This is the synthesized content.';

    $mockResponse = new MockResponse(json_encode([
        'choices' => [
            ['message' => ['content' => $expectedContent]],
        ],
    ]));

    $client = makeMistralClient(new MockHttpClient([$mockResponse]));

    $result = $client->complete(MISTRAL_SYSTEM, MISTRAL_CONTENT);

    expect($result)->toBe($expectedContent);
});

test('complete envoie une requête POST à l\'endpoint Mistral', function (): void {
    $mockResponse = new MockResponse(json_encode([
        'choices' => [['message' => ['content' => 'BRIEFLY AI: Test']]],
    ]));

    $http = new MockHttpClient([$mockResponse]);
    makeMistralClient($http)->complete(MISTRAL_SYSTEM, MISTRAL_CONTENT);

    expect($http->getRequestsCount())->toBe(1);
});

test('complete envoie le system prompt et le contenu utilisateur dans les messages', function (): void {
    $capturedRequest = null;

    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedRequest): MockResponse {
        $capturedRequest = $options;

        return new MockResponse(json_encode([
            'choices' => [['message' => ['content' => 'BRIEFLY AI: Test']]],
        ]));
    });

    makeMistralClient($http)->complete(MISTRAL_SYSTEM, MISTRAL_CONTENT);

    expect($capturedRequest)->not->toBeNull();

    $body = json_decode((string) ($capturedRequest['body'] ?? '{}'), true);
    $messages = $body['messages'] ?? [];

    expect($messages)->toHaveCount(2);
    expect($messages[0]['role'])->toBe('system');
    expect($messages[0]['content'])->toBe(MISTRAL_SYSTEM);
    expect($messages[1]['role'])->toBe('user');
    expect($messages[1]['content'])->toBe(MISTRAL_CONTENT);
});

test('complete inclut le header Authorization Bearer', function (): void {
    $capturedHeaders = [];

    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
        $capturedHeaders = $options['headers'] ?? [];

        return new MockResponse(json_encode([
            'choices' => [['message' => ['content' => 'BRIEFLY AI: Test']]],
        ]));
    });

    makeMistralClient($http)->complete(MISTRAL_SYSTEM, MISTRAL_CONTENT);

    $authHeader = '';
    foreach ($capturedHeaders as $header) {
        if (str_starts_with((string) $header, 'Authorization:')) {
            $authHeader = $header;
            break;
        }
    }

    expect($authHeader)->toContain('Bearer ' . MISTRAL_FAKE_KEY);
});

// ── HTTP 5xx → SynthesisUnavailableException ──────────────────────────────────

test('complete lève SynthesisUnavailableException si HTTP 500', function (): void {
    $http = new MockHttpClient([new MockResponse('Server Error', ['http_code' => 500])]);
    $client = makeMistralClient($http);

    expect(static fn () => $client->complete(MISTRAL_SYSTEM, MISTRAL_CONTENT))
        ->toThrow(SynthesisUnavailableException::class);
});

test('complete lève SynthesisUnavailableException si HTTP 503', function (): void {
    $http = new MockHttpClient([new MockResponse('Service Unavailable', ['http_code' => 503])]);
    $client = makeMistralClient($http);

    expect(static fn () => $client->complete(MISTRAL_SYSTEM, MISTRAL_CONTENT))
        ->toThrow(SynthesisUnavailableException::class);
});

// ── Réponse vide → SynthesisUnavailableException ──────────────────────────────

test('complete lève SynthesisUnavailableException si content vide dans la réponse', function (): void {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'choices' => [['message' => ['content' => '']]],
        ])),
    ]);
    $client = makeMistralClient($http);

    expect(static fn () => $client->complete(MISTRAL_SYSTEM, MISTRAL_CONTENT))
        ->toThrow(SynthesisUnavailableException::class);
});

// ── PII-free assertion ─────────────────────────────────────────────────────────

test('complete NE contient PAS d\'UUID utilisateur dans le prompt (PII-free)', function (): void {
    $capturedBody = null;

    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
        $capturedBody = $options['body'] ?? '';

        return new MockResponse(json_encode([
            'choices' => [['message' => ['content' => 'BRIEFLY AI: Test']]],
        ]));
    });

    // Système contient le prompt sans PII
    makeMistralClient($http)->complete(
        'System without PII: summarize the article',
        'Article text without user email or UUID',
    );

    // Vérifier que le payload envoyé ne contient pas de pattern UUID v4
    expect((string) $capturedBody)->not->toMatch(
        '/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i',
    );
});

test('complete NE contient PAS d\'adresse email dans le prompt (PII-free)', function (): void {
    $capturedBody = null;

    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
        $capturedBody = $options['body'] ?? '';

        return new MockResponse(json_encode([
            'choices' => [['message' => ['content' => 'BRIEFLY AI: Test']]],
        ]));
    });

    makeMistralClient($http)->complete(
        'System without PII',
        'Article text without personal information',
    );

    expect((string) $capturedBody)->not->toMatch('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/');
});
