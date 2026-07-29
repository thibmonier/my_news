<?php

declare(strict_types=1);

use App\Application\Feed\ValidateSource\ValidateSourceHandler;
use App\Application\Feed\ValidateSource\ValidateSourceMessage;
use App\Domain\Feed\FeedType;
use App\Domain\Feed\Source;
use App\Domain\Feed\SourceRepositoryInterface;
use App\Domain\Feed\SourceStatus;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/*
 * Tests unitaires — ValidateSourceHandler
 *
 * Vérifie la logique de validation asynchrone des sources :
 * - URL valide (HEAD 200, Content-Type rss/xml/atom) → status active
 * - URL inaccessible (HTTP 404) → status validation_failed
 * - Content-Type HTML → status validation_failed
 * - ConnectException (réseau inaccessible) → status validation_failed + log ERROR
 * - Source introuvable en base → log warning, pas de mise à jour
 * - URL non-HTTPS → status validation_failed (défense en profondeur)
 * - 0 PII dans les logs (pas d'email, pas d'IP utilisateur)
 */

uses(PHPUnit\Framework\TestCase::class);

function makeSourceForValidation(string $url = 'https://valid.example.com/feed'): Source
{
    return new Source(
        id: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
        name: 'Test Feed',
        url: $url,
        feedType: FeedType::Rss,
        status: SourceStatus::PendingValidation,
    );
}

function buildHandler(
    SourceRepositoryInterface $repo,
    HttpClientInterface $http,
    LoggerInterface $logger,
): ValidateSourceHandler {
    return new ValidateSourceHandler($repo, $http, $logger);
}

// ── Nominal : URL valide → active ────────────────────────────────────────

test('ValidateSourceHandler active la source si HEAD 200 et Content-Type rss+xml', function (): void {
    $source = makeSourceForValidation();
    $message = new ValidateSourceMessage($source->getId());

    $repo = $this->createMock(SourceRepositoryInterface::class);
    $repo->method('findById')->willReturn($source);
    $repo->expects($this->once())
        ->method('updateStatus')
        ->with($source->getId(), SourceStatus::Active);

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $response->method('getHeaders')->willReturn(['content-type' => ['application/rss+xml; charset=utf-8']]);

    $http = $this->createMock(HttpClientInterface::class);
    $http->method('request')->willReturn($response);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('info');
    $logger->expects($this->never())->method('error');

    $handler = buildHandler($repo, $http, $logger);
    $handler($message);
});

test('ValidateSourceHandler active la source si Content-Type contient "xml"', function (): void {
    $source = makeSourceForValidation();
    $message = new ValidateSourceMessage($source->getId());

    $repo = $this->createMock(SourceRepositoryInterface::class);
    $repo->method('findById')->willReturn($source);
    $repo->expects($this->once())->method('updateStatus')->with($source->getId(), SourceStatus::Active);

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $response->method('getHeaders')->willReturn(['content-type' => ['text/xml']]);

    $http = $this->createMock(HttpClientInterface::class);
    $http->method('request')->willReturn($response);

    $logger = $this->createMock(LoggerInterface::class);

    $handler = buildHandler($repo, $http, $logger);
    $handler($message);
});

test('ValidateSourceHandler active la source si Content-Type contient "atom"', function (): void {
    $source = makeSourceForValidation();
    $message = new ValidateSourceMessage($source->getId());

    $repo = $this->createMock(SourceRepositoryInterface::class);
    $repo->method('findById')->willReturn($source);
    $repo->expects($this->once())->method('updateStatus')->with($source->getId(), SourceStatus::Active);

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $response->method('getHeaders')->willReturn(['content-type' => ['application/atom+xml']]);

    $http = $this->createMock(HttpClientInterface::class);
    $http->method('request')->willReturn($response);

    $logger = $this->createMock(LoggerInterface::class);

    $handler = buildHandler($repo, $http, $logger);
    $handler($message);
});

// ── HTTP 404 → validation_failed ────────────────────────────────────────

test('ValidateSourceHandler passe en validation_failed si HTTP 404', function (): void {
    $source = makeSourceForValidation();
    $message = new ValidateSourceMessage($source->getId());

    $repo = $this->createMock(SourceRepositoryInterface::class);
    $repo->method('findById')->willReturn($source);
    $repo->expects($this->once())
        ->method('updateStatus')
        ->with($source->getId(), SourceStatus::ValidationFailed);

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(404);
    $response->method('getHeaders')->willReturn([]);

    $http = $this->createMock(HttpClientInterface::class);
    $http->method('request')->willReturn($response);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $handler = buildHandler($repo, $http, $logger);
    $handler($message);
});

// ── Content-Type HTML → validation_failed ───────────────────────────────

test('ValidateSourceHandler passe en validation_failed si Content-Type text/html', function (): void {
    $source = makeSourceForValidation();
    $message = new ValidateSourceMessage($source->getId());

    $repo = $this->createMock(SourceRepositoryInterface::class);
    $repo->method('findById')->willReturn($source);
    $repo->expects($this->once())
        ->method('updateStatus')
        ->with($source->getId(), SourceStatus::ValidationFailed);

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $response->method('getHeaders')->willReturn(['content-type' => ['text/html; charset=utf-8']]);

    $http = $this->createMock(HttpClientInterface::class);
    $http->method('request')->willReturn($response);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $handler = buildHandler($repo, $http, $logger);
    $handler($message);
});

// ── ConnectException → validation_failed + log ERROR ───────────────────

test('ValidateSourceHandler passe en validation_failed sur ConnectException', function (): void {
    $source = makeSourceForValidation();
    $message = new ValidateSourceMessage($source->getId());

    $repo = $this->createMock(SourceRepositoryInterface::class);
    $repo->method('findById')->willReturn($source);
    $repo->expects($this->once())
        ->method('updateStatus')
        ->with($source->getId(), SourceStatus::ValidationFailed);

    // Use concrete exception class — TransportExceptionInterface::getMessage() is from \Throwable
    // and cannot be configured on PHPUnit mocks of interfaces
    $transportException = new class('Connection refused') extends RuntimeException implements TransportExceptionInterface {};

    $http = $this->createMock(HttpClientInterface::class);
    $http->method('request')->willThrowException($transportException);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');
    $logger->expects($this->never())->method('info');

    $handler = buildHandler($repo, $http, $logger);
    $handler($message);
});

// ── Source introuvable → log warning, pas de mise à jour ─────────────────

test('ValidateSourceHandler log un warning si source introuvable', function (): void {
    $message = new ValidateSourceMessage('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

    $repo = $this->createMock(SourceRepositoryInterface::class);
    $repo->method('findById')->willReturn(null);
    $repo->expects($this->never())->method('updateStatus');

    $http = $this->createMock(HttpClientInterface::class);
    $http->expects($this->never())->method('request');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $handler = buildHandler($repo, $http, $logger);
    $handler($message);
});

// ── Défense en profondeur : URL non-HTTPS → validation_failed ─────────

test('ValidateSourceHandler refuse les URLs non-HTTPS (défense en profondeur)', function (): void {
    $source = makeSourceForValidation('http://insecure.example.com/feed');
    $message = new ValidateSourceMessage($source->getId());

    $repo = $this->createMock(SourceRepositoryInterface::class);
    $repo->method('findById')->willReturn($source);
    $repo->expects($this->once())
        ->method('updateStatus')
        ->with($source->getId(), SourceStatus::ValidationFailed);

    $http = $this->createMock(HttpClientInterface::class);
    $http->expects($this->never())->method('request'); // aucune requête HTTP émise

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $handler = buildHandler($repo, $http, $logger);
    $handler($message);
});

// ── Log : 0 PII ────────────────────────────────────────────────────────

test('ValidateSourceHandler ne logue pas de PII dans le contexte d\'erreur', function (): void {
    $source = makeSourceForValidation();
    $message = new ValidateSourceMessage($source->getId());

    $repo = $this->createMock(SourceRepositoryInterface::class);
    $repo->method('findById')->willReturn($source);
    $repo->method('updateStatus');

    $transportException = new class('Connection refused') extends RuntimeException implements TransportExceptionInterface {};

    $http = $this->createMock(HttpClientInterface::class);
    $http->method('request')->willThrowException($transportException);

    $capturedContext = null;
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('error')
        ->willReturnCallback(function (string $message, array $context = []) use (&$capturedContext): void {
            $capturedContext = $context;
        });

    $handler = buildHandler($repo, $http, $logger);
    $handler($message);

    // Vérifie qu'aucune clé PII (email, IP client, user_id) n'est loguée
    expect($capturedContext)->not()->toHaveKey('email')
        ->and($capturedContext)->not()->toHaveKey('user_id')
        ->and($capturedContext)->not()->toHaveKey('ip');
});
