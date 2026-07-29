<?php

declare(strict_types=1);

namespace App\Infrastructure\Feed\Persistence;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entité Doctrine — représentation persistée d'un Article ingéré.
 *
 * Réside dans la couche Infrastructure UNIQUEMENT.
 * Les attributs #[ORM] ne doivent JAMAIS apparaître dans src/Domain/ (constitution §4).
 *
 * Contrainte UNIQUE sur content_hash garantit la déduplication côté base de données.
 * L'index sur published_at optimise le tri de la liste admin et la génération de briefs.
 */
#[ORM\Entity]
#[ORM\Table(name: 'articles')]
#[ORM\UniqueConstraint(name: 'articles_content_hash_unique', columns: ['content_hash'])]
#[ORM\Index(name: 'idx_articles_published_at', columns: ['published_at'])]
#[ORM\Index(name: 'idx_articles_source_id', columns: ['source_id'])]
class DoctrineArticleEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceId;

    #[ORM\Column(length: 1024)]
    private string $title;

    #[ORM\Column(length: 2048)]
    private string $url;

    /** SHA-256 de l'URL canonique — 64 caractères hexadécimaux */
    #[ORM\Column(length: 64, unique: true)]
    private string $contentHash;

    #[ORM\Column]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column(type: 'text')]
    private string $rawContent;

    #[ORM\Column]
    private \DateTimeImmutable $fetchAt;

    #[ORM\Column(length: 64, nullable: true)]
    // @phpstan-ignore-next-line property.unusedType (Doctrine sets string via reflection; Sprint 1 null, EPIC-002 will assign)
    private ?string $clusterId = null;

    /** true par défaut en Sprint 1 (simplifié) */
    #[ORM\Column]
    private bool $isFullTextAccessible = true;

    public function __construct(
        Uuid $id,
        Uuid $sourceId,
        string $title,
        string $url,
        string $contentHash,
        \DateTimeImmutable $publishedAt,
        string $rawContent,
        \DateTimeImmutable $fetchAt,
    ) {
        $this->id = $id;
        $this->sourceId = $sourceId;
        $this->title = $title;
        $this->url = $url;
        $this->contentHash = $contentHash;
        $this->publishedAt = $publishedAt;
        $this->rawContent = $rawContent;
        $this->fetchAt = $fetchAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSourceId(): Uuid
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

    public function getContentHash(): string
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
