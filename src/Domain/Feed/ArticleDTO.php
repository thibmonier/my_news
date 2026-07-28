<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * DTO de transfert — Article parsé par le SourceFetcher.
 *
 * Transporte les données brutes d'un article depuis le fetcher Infrastructure
 * vers le handler Application.
 *
 * PHP pur — aucune dépendance framework (constitution §4, deptrac Domain:[]).
 */
final class ArticleDTO
{
    public function __construct(
        /** UUID de la source parente */
        public readonly string $sourceId,
        /** Titre de l'article (non vide) */
        public readonly string $title,
        /** URL originale de l'article */
        public readonly string $url,
        /** URL canonique normalisée (sans UTM, fragment, trailing slash) */
        public readonly string $canonicalUrl,
        /** Empreinte SHA-256 de l'URL canonique */
        public readonly ContentHash $contentHash,
        /** Contenu brut (description/summary du flux) */
        public readonly string $rawContent,
        /** Date de publication UTC */
        public readonly \DateTimeImmutable $publishedAt,
    ) {
    }
}
