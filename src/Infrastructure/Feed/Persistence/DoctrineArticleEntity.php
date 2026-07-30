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

    /**
     * Catégorie éditoriale persistée (US-005).
     * Valeur parmi : 'ai_insight', 'geopolitics', 'productivity', 'research', 'sustainability'.
     * DEFAULT 'productivity' en base (articles sans catégorie assignée = sprint 1).
     */
    #[ORM\Column(length: 50, options: ['default' => 'productivity'])]
    private string $category = 'productivity';

    /**
     * SimHash 64 bits du titre normalisé (US-022).
     *
     * NULL si le titre est vide, réduit à des stopwords uniquement,
     * ou si le calcul SimHash a échoué.
     *
     * Doctrine bigint → string PHP (compatibilité 32/64 bits).
     */
    #[ORM\Column(type: 'bigint', nullable: true, name: 'title_simhash')]
    // @phpstan-ignore-next-line property.unusedType (Doctrine hydrate en string via reflection)
    private ?string $titleSimhash = null;

    /**
     * Vrai si cet article est un doublon sémantique d'un article déjà indexé (US-022).
     *
     * Les doublons sont CONSERVÉS en base (traçabilité) mais exclus du Daily Brief.
     */
    #[ORM\Column(name: 'is_duplicate', options: ['default' => false])]
    private bool $isDuplicate = false;

    /**
     * Référence self-référentielle vers l'article original (US-022).
     *
     * ON DELETE SET NULL : la traçabilité est préservée si l'original est supprimé.
     * NULL si l'article n'est pas un doublon.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'duplicate_of', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    // @phpstan-ignore-next-line property.unusedType (Doctrine hydrate via ORM reflection)
    private ?self $duplicateOf = null;

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

    /**
     * Catégorie éditoriale de l'article (US-005).
     * Valeur parmi les 5 cases de ArticleCategory (string backing).
     */
    public function getCategory(): string
    {
        return $this->category;
    }

    /** SimHash 64 bits du titre (null si non calculé). Doctrine bigint → string. */
    public function getTitleSimhash(): ?string
    {
        return $this->titleSimhash;
    }

    /** Vrai si l'article est marqué comme doublon sémantique (US-022). */
    public function isDuplicate(): bool
    {
        return $this->isDuplicate;
    }

    /** Article original dont celui-ci est le doublon (null si non-doublon). */
    public function getDuplicateOf(): ?self
    {
        return $this->duplicateOf;
    }
}
