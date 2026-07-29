<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Entité domaine — Source RSS/Atom.
 *
 * Représente une source d'actualités à ingérer périodiquement.
 * PHP pur — AUCUN attribut #[ORM], AUCUN import Symfony/Doctrine.
 * Constitution §4 : entités Domain = classes PHP pures, interfaces de repository dans le Domain.
 *
 * Immuable : toutes les propriétés sont readonly.
 * Les mutations passent par la couche Application → Infrastructure repository.
 */
final class Source
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly string $url,
        private readonly FeedType $feedType,
        private readonly SourceStatus $status,
        private readonly ?\DateTimeImmutable $lastFetchedAt = null,
        private readonly ?\DateTimeImmutable $lastErrorAt = null,
        private readonly ?string $etag = null,
        private readonly ?string $lastModified = null,
        private readonly int $fetchIntervalMinutes = 30,
        private readonly ?\DateTimeImmutable $deletedAt = null,
    ) {
    }

    /** @return non-empty-string */
    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getFeedType(): FeedType
    {
        return $this->feedType;
    }

    public function getStatus(): SourceStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function getLastErrorAt(): ?\DateTimeImmutable
    {
        return $this->lastErrorAt;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }

    public function getFetchIntervalMinutes(): int
    {
        return $this->fetchIntervalMinutes;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
