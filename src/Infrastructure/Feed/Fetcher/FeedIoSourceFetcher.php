<?php

declare(strict_types=1);

namespace App\Infrastructure\Feed\Fetcher;

use App\Domain\Feed\ArticleDTO;
use App\Domain\Feed\ContentHash;
use App\Domain\Feed\Source;
use App\Domain\Feed\SourceFetcherInterface;
use FeedIo\FeedIo;
use Psr\Log\LoggerInterface;

/**
 * Adapter FeedIo — Implémentation de SourceFetcherInterface.
 *
 * Responsabilités :
 * - Fetch HTTP du flux RSS/Atom via FeedIo 6.x (User-Agent "BrieflyAI/1.0" configuré côté client)
 * - Normalisation de l'URL canonique (suppression UTM, fragment, trailing slash)
 * - Calcul du content_hash SHA-256 sur l'URL canonique
 * - Conversion des items FeedIo en ArticleDTO[]
 *
 * Sécurité SSRF : la source provient de la base de données (seed admin),
 * jamais d'une entrée utilisateur directe. Validation URL supplémentaire incluse.
 *
 * Couche Infrastructure : peut dépendre de FeedIo.
 * Deptrac : Infrastructure:[Domain, Application].
 */
final class FeedIoSourceFetcher implements SourceFetcherInterface
{
    /** @var list<string> Paramètres UTM à supprimer lors de la normalisation */
    private const UTM_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /** @var list<string> Schémas URL autorisés (SSRF : HTTPS/HTTP uniquement) */
    private const ALLOWED_SCHEMES = ['https', 'http'];

    public function __construct(
        private readonly FeedIo $feedIo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \RuntimeException Si le flux est inaccessible (HTTP 5xx, timeout, XML invalide)
     *
     * @return list<ArticleDTO>
     */
    public function fetch(Source $source): array
    {
        $this->validateUrl($source->getUrl());

        $result = $this->feedIo->read($source->getUrl());
        $feed = $result->getFeed();
        $articles = [];

        foreach ($feed as $item) {
            /** @var \FeedIo\Feed\ItemInterface $item */
            $itemUrl = $item->getLink();

            if (null === $itemUrl || '' === $itemUrl) {
                continue;
            }

            $canonicalUrl = $this->normalizeUrl($itemUrl);

            if ('' === $canonicalUrl) {
                continue;
            }

            $contentHash = ContentHash::fromCanonicalUrl($canonicalUrl);

            $publishedAt = $item->getLastModified();
            $publishedAtImmutable = null !== $publishedAt
                ? \DateTimeImmutable::createFromMutable($publishedAt)->setTimezone(new \DateTimeZone('UTC'))
                : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $rawContent = $item->getSummary() ?? $item->getContent() ?? '';

            $articles[] = new ArticleDTO(
                sourceId: $source->getId(),
                title: $item->getTitle() ?? '(sans titre)',
                url: $itemUrl,
                canonicalUrl: $canonicalUrl,
                contentHash: $contentHash,
                rawContent: $rawContent,
                publishedAt: $publishedAtImmutable,
            );
        }

        $this->logger->debug('FeedIoSourceFetcher: flux parsé', [
            'source_id' => $source->getId(),
            'source_url' => $source->getUrl(),
            'count' => \count($articles),
        ]);

        return $articles;
    }

    /**
     * Normalise une URL en supprimant les paramètres UTM, le fragment et le trailing slash.
     */
    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if (false === $parts) {
            return rtrim($url, '/');
        }

        // Recomposer sans fragment
        $scheme = isset($parts['scheme']) ? ($parts['scheme'] . '://') : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? (':' . $parts['port']) : '';
        $path = rtrim($parts['path'] ?? '', '/');
        $query = $parts['query'] ?? '';

        // Supprimer les paramètres UTM de la query string
        if ('' !== $query) {
            parse_str($query, $params);
            foreach (self::UTM_PARAMS as $utmParam) {
                unset($params[$utmParam]);
            }
            $query = '' !== ($rebuilt = http_build_query($params)) ? ('?' . $rebuilt) : '';
        } else {
            $query = '';
        }

        return $scheme . $host . $port . $path . $query;
    }

    /**
     * Valide que l'URL source est bien HTTP/HTTPS (protection SSRF — constitution §6).
     *
     * @throws \RuntimeException Si le schéma n'est pas autorisé
     */
    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);

        if (false === $parts || !isset($parts['scheme'])) {
            throw new \RuntimeException(\sprintf('URL de source invalide : "%s"', $url));
        }

        if (!\in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            throw new \RuntimeException(\sprintf('Schéma URL non autorisé "%s" pour la source "%s" (SSRF protection).', $parts['scheme'], $url));
        }
    }
}
