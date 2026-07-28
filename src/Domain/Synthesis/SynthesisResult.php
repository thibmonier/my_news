<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Entité Domaine — Résultat de synthèse IA persisté.
 *
 * PHP pur — AUCUN attribut #[ORM], AUCUN import Symfony/Doctrine.
 * Constitution §4 : entités Domain = classes PHP pures.
 *
 * Traçabilité analytics Sprint 1 : persister systématiquement chaque synthèse.
 * RGPD : aucune FK utilisateur — `url_hash` (SHA-256 de l'URL) à la place de l'URL brute.
 *
 * La classe Doctrine correspondante est DoctrineSynthesisResultEntity (Infrastructure).
 */
final class SynthesisResult
{
    /**
     * @param string $id UUID v4 (Symfony\Component\Uid\Uuid::v4()->toRfc4122())
     * @param string $urlHash SHA-256 hexadécimal de l'URL source (64 caractères)
     * @param string $level Niveau de synthèse : 'standard' (Sprint 1)
     * @param string $content Condensé IA — contenu complet incluant "BRIEFLY AI:"
     * @param string[] $keyPoints Tableau de 3 points clés
     * @param string[] $sources Tableau de sources citées
     * @param \DateTimeImmutable $createdAt Horodatage UTC de création
     */
    public function __construct(
        private readonly string $id,
        private readonly string $urlHash,
        private readonly string $level,
        private readonly string $content,
        private readonly array $keyPoints,
        private readonly array $sources,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): string
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
