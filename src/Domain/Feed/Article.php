<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Entité domaine — Article ingéré depuis une source RSS/Atom.
 *
 * PHP pur — AUCUN attribut #[ORM], AUCUN import Symfony/Doctrine.
 * Constitution §4 : entités Domain = classes PHP pures.
 *
 * La déduplication repose sur content_hash (SHA-256 de l'URL canonique).
 * cluster_id est null en Sprint 1 — pré-calculé par EPIC-002 (US-016) ultérieurement.
 */
final class Article
{
    public function __construct(
        private readonly string $id,
        private readonly string $sourceId,
        private readonly string $title,
        private readonly string $url,
        private readonly ContentHash $contentHash,
        private readonly \DateTimeImmutable $publishedAt,
        private readonly string $rawContent,
        private readonly \DateTimeImmutable $fetchAt,
        private readonly ?string $clusterId = null,
        private readonly bool $isFullTextAccessible = true,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getContentHash(): ContentHash
    {
        return $this->contentHash;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getRawContent(): string
    {
        return $this->rawContent;
    }

    public function getFetchAt(): \DateTimeImmutable
    {
        return $this->fetchAt;
    }

    public function getClusterId(): ?string
    {
        return $this->clusterId;
    }

    public function isFullTextAccessible(): bool
    {
        return $this->isFullTextAccessible;
    }
}
