<?php

declare(strict_types=1);

namespace App\Infrastructure\Feed\Persistence;

use App\Domain\Feed\FeedType;
use App\Domain\Feed\Source;
use App\Domain\Feed\SourceStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entité Doctrine — représentation persistée d'une Source RSS/Atom.
 *
 * Réside dans la couche Infrastructure UNIQUEMENT.
 * Les attributs #[ORM] ne doivent JAMAIS apparaître dans src/Domain/ (constitution §4).
 *
 * Mapping : attributs PHP 8.4, auto-découverts via doctrine.yaml (Infrastructure namespace).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sources')]
class DoctrineSourceEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(length: 10, enumType: FeedType::class)]
    private FeedType $feedType;

    #[ORM\Column(length: 20, enumType: SourceStatus::class)]
    private SourceStatus $status;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastErrorAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    // @phpstan-ignore-next-line property.unusedType (Doctrine sets string via reflection when loading from DB)
    private ?string $etag = null;

    #[ORM\Column(length: 255, nullable: true)]
    // @phpstan-ignore-next-line property.unusedType (Doctrine sets string via reflection when loading from DB)
    private ?string $lastModified = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $id,
        string $name,
        string $url,
        FeedType $feedType,
        SourceStatus $status,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->url = $url;
        $this->feedType = $feedType;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
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

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function setLastFetchedAt(\DateTimeImmutable $at): void
    {
        $this->lastFetchedAt = $at;
    }

    public function getLastErrorAt(): ?\DateTimeImmutable
    {
        return $this->lastErrorAt;
    }

    public function setLastErrorAt(\DateTimeImmutable $at): void
    {
        $this->lastErrorAt = $at;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Convertit l'entité Doctrine en entité Domain (anti-corruption layer).
     */
    public function toDomainEntity(): Source
    {
        return new Source(
            id: $this->id->toRfc4122(),
            name: $this->name,
            url: $this->url,
            feedType: $this->feedType,
            status: $this->status,
            lastFetchedAt: $this->lastFetchedAt,
            lastErrorAt: $this->lastErrorAt,
            etag: $this->etag,
            lastModified: $this->lastModified,
        );
    }
}
