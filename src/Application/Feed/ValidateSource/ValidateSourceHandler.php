<?php

declare(strict_types=1);

namespace App\Application\Feed\ValidateSource;

use App\Domain\Feed\SourceRepositoryInterface;
use App\Domain\Feed\SourceStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Handler Messenger — Valide une source RSS/Atom par HEAD HTTP.
 *
 * Effectue un HEAD vers l'URL de la source, vérifie que le Content-Type
 * contient 'rss', 'xml' ou 'atom'. Si valide → status=active.
 * Sinon (404, timeout, Content-Type HTML, etc.) → status=validation_failed.
 *
 * Sécurité SSRF (défense en profondeur) : le handler re-vérifie que l'URL
 * est HTTPS avant la requête (la validation formulaire est la première ligne).
 *
 * Deptrac Application:[Domain] — dépend de HttpClientInterface (port Symfony).
 */
#[AsMessageHandler]
final class ValidateSourceHandler
{
    private const TIMEOUT_SECONDS = 10;

    /** Content-Types acceptés comme flux valides. */
    private const VALID_CONTENT_TYPE_KEYWORDS = ['rss', 'xml', 'atom', 'feed'];

    public function __construct(
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ValidateSourceMessage $message): void
    {
        $source = $this->sourceRepository->findById($message->sourceId);

        if (null === $source) {
            $this->logger->warning('ValidateSourceHandler: source introuvable', [
                'source_id' => $message->sourceId,
            ]);

            return;
        }

        $url = $source->getUrl();

        // Défense en profondeur SSRF : refus des schémas non-HTTPS
        if (!str_starts_with(strtolower($url), 'https://')) {
            $this->failValidation($message->sourceId, $url, 'schéma non-HTTPS');

            return;
        }

        try {
            $response = $this->httpClient->request('HEAD', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';

            if ($statusCode < 200 || $statusCode >= 400) {
                $this->failValidation($message->sourceId, $url, "HTTP {$statusCode}");

                return;
            }

            $contentTypeLower = strtolower($contentType);
            $isValidFeed = false;

            foreach (self::VALID_CONTENT_TYPE_KEYWORDS as $keyword) {
                if (str_contains($contentTypeLower, $keyword)) {
                    $isValidFeed = true;
                    break;
                }
            }

            if (!$isValidFeed) {
                $this->failValidation($message->sourceId, $url, "Content-Type invalide: {$contentType}");

                return;
            }

            $this->sourceRepository->updateStatus($message->sourceId, SourceStatus::Active);

            $this->logger->info('ValidateSourceHandler: source validée et activée', [
                'source_id' => $message->sourceId,
                'content_type' => $contentType,
            ]);
        } catch (TransportExceptionInterface $e) {
            $this->failValidation($message->sourceId, $url, 'ConnectException: ' . $e->getMessage());
        }
    }

    /** @param non-empty-string $sourceId */
    private function failValidation(string $sourceId, string $url, string $reason): void
    {
        $this->sourceRepository->updateStatus($sourceId, SourceStatus::ValidationFailed);

        $this->logger->error('ValidateSourceHandler: validation échouée', [
            'source_id' => $sourceId,
            'url' => $url,
            'reason' => $reason,
        ]);
    }
}
