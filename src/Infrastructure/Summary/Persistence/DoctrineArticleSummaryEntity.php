<?php

declare(strict_types=1);

namespace App\Infrastructure\Summary\Persistence;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entité Doctrine — Persistance des condensés IA par article (US-004).
 *
 * Réside dans la couche Infrastructure UNIQUEMENT.
 * Les attributs #[ORM] ne doivent JAMAIS apparaître dans src/Domain/ (constitution §4).
 *
 * Table `article_summaries` :
 * - id           : UUID v4, PK
 * - article_id   : UUID de l'article source (index — pas de FK constraint pour découplage)
 * - key_points   : JSONB array de puces (3-4 éléments ≤ 120 chars)
 * - model_version: version du modèle IA (traçabilité RGPD)
 * - is_degraded  : true si mode dégradé (tous fournisseurs KO)
 * - degraded_content : extrait RSS brut ≤ 280 chars (null si non dégradé)
 * - cached_at    : horodatage UTC de génération
 * - expires_at   : cached_at + 24h (TTL logique)
 *
 * RGPD : aucune FK utilisateur — article_id uniquement (UUID non-séquentiel).
 */
#[ORM\Entity]
#[ORM\Table(name: 'article_summaries')]
#[ORM\Index(name: 'idx_article_summaries_article_id', columns: ['article_id'])]
#[ORM\Index(name: 'idx_article_summaries_expires_at', columns: ['expires_at'])]
class DoctrineArticleSummaryEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** UUID de l'article source — index pour recherche rapide (analytics). */
    #[ORM\Column(type: 'uuid')]
    private Uuid $articleId;

    /**
     * Tableau de 3-4 puces ≤ 120 chars chacune.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $keyPoints;

    /** Version du modèle IA ('mistral-small-latest', 'gpt-4o-mini', '' si dégradé). */
    #[ORM\Column(length: 64)]
    private string $modelVersion;

    /** true si tous les fournisseurs IA étaient KO lors de la génération. */
    #[ORM\Column]
    private bool $isDegraded;

    /** Extrait RSS brut ≤ 280 chars (null si non dégradé). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $degradedContent;

    /** Horodatage UTC de génération. */
    #[ORM\Column]
    private \DateTimeImmutable $cachedAt;

    /** Horodatage UTC d'expiration (= cachedAt + 24h). */
    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /**
     * @param list<string> $keyPoints
     */
    public function __construct(
        Uuid $id,
        Uuid $articleId,
        array $keyPoints,
        string $modelVersion,
        bool $isDegraded,
        ?string $degradedContent,
        \DateTimeImmutable $cachedAt,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id = $id;
        $this->articleId = $articleId;
        $this->keyPoints = $keyPoints;
        $this->modelVersion = $modelVersion;
        $this->isDegraded = $isDegraded;
        $this->degradedContent = $degradedContent;
        $this->cachedAt = $cachedAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArticleId(): Uuid
    {
        return $this->articleId;
    }

    /** @return list<string> */
    public function getKeyPoints(): array
    {
        return $this->keyPoints;
    }

    public function getModelVersion(): string
    {
        return $this->modelVersion;
    }

    public function isDegraded(): bool
    {
        return $this->isDegraded;
    }

    public function getDegradedContent(): ?string
    {
        return $this->degradedContent;
    }

    public function getCachedAt(): \DateTimeImmutable
    {
        return $this->cachedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
