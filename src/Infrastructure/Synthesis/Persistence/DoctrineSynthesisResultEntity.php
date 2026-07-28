<?php

declare(strict_types=1);

namespace App\Infrastructure\Synthesis\Persistence;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entité Doctrine — représentation persistée d'un résultat de synthèse IA.
 *
 * Réside dans la couche Infrastructure UNIQUEMENT.
 * Les attributs #[ORM] ne doivent JAMAIS apparaître dans src/Domain/ (constitution §4).
 *
 * Table `synthesis_results` :
 * - `url_hash`   : SHA-256 de l'URL source (analytics, pas de déduplication Sprint 1)
 * - `level`      : 'standard' (Sprint 1), 'deep' ou 'brief' en US-015 backlog
 * - `key_points` : JSONB tableau de 3 points clés numérotés 01/02/03
 * - `sources`    : JSONB tableau de sources citées
 *
 * RGPD : aucune FK utilisateur — conformité §4 story US-010 (pas de traçabilité nominative).
 */
#[ORM\Entity]
#[ORM\Table(name: 'synthesis_results')]
#[ORM\Index(name: 'idx_synthesis_results_url_hash', columns: ['url_hash'])]
#[ORM\Index(name: 'idx_synthesis_results_created_at', columns: ['created_at'])]
class DoctrineSynthesisResultEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** SHA-256 hexadécimal de l'URL source — 64 caractères */
    #[ORM\Column(length: 64)]
    private string $urlHash;

    /** Niveau de synthèse : 'standard' en Sprint 1 */
    #[ORM\Column(length: 16)]
    private string $level;

    /** Condensé IA incluant le préfixe "BRIEFLY AI:" */
    #[ORM\Column(type: 'text')]
    private string $content;

    /**
     * Tableau de 3 points clés numérotés 01/02/03.
     *
     * @var string[]
     */
    #[ORM\Column(type: 'json')]
    private array $keyPoints;

    /**
     * Tableau de sources citées.
     *
     * @var string[]
     */
    #[ORM\Column(type: 'json')]
    private array $sources;

    /** Horodatage UTC de création — analytics Sprint 1 */
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param string[] $keyPoints
     * @param string[] $sources
     */
    public function __construct(
        Uuid $id,
        string $urlHash,
        string $level,
        string $content,
        array $keyPoints,
        array $sources,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->urlHash = $urlHash;
        $this->level = $level;
        $this->content = $content;
        $this->keyPoints = $keyPoints;
        $this->sources = $sources;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUrlHash(): string
    {
        return $this->urlHash;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /** @return string[] */
    public function getKeyPoints(): array
    {
        return $this->keyPoints;
    }

    /** @return string[] */
    public function getSources(): array
    {
        return $this->sources;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
