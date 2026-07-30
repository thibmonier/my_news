<?php

declare(strict_types=1);

namespace App\Infrastructure\Brief\Persistence;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entité Doctrine — Persistance des synthèses narratives Featured Summary (US-006).
 *
 * Réside dans la couche Infrastructure UNIQUEMENT.
 * Les attributs #[ORM] ne doivent JAMAIS apparaître dans src/Domain/ (constitution §4).
 *
 * Table `daily_brief_summaries` :
 * - id            : UUID v4, PK
 * - brief_id      : UUID FK UNIQUE → daily_briefs.id (unicité : 1 synthèse par brief)
 * - content       : TEXT  — texte narratif 80-120 mots (ou texte fallback)
 * - model_version : VARCHAR(64) — 'mistral-small-latest' ou '' si is_fallback=true
 * - generated_at  : TIMESTAMPTZ UTC de génération
 * - is_fallback   : BOOLEAN DEFAULT FALSE — true si Mistral KO lors de la génération
 *
 * RGPD : aucune FK utilisateur — contenu éditorial public uniquement.
 * INV-2 : is_fallback = true → badge émeraude NON affiché côté présentation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'daily_brief_summaries')]
#[ORM\UniqueConstraint(name: 'daily_brief_summaries_brief_id_unique', columns: ['brief_id'])]
#[ORM\Index(name: 'idx_daily_brief_summaries_generated_at', columns: ['generated_at'])]
class DoctrineDailyBriefSummaryEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** UUID du DailyBrief source — contrainte UNIQUE (1 synthèse par brief). */
    #[ORM\Column(type: 'uuid')]
    private Uuid $briefId;

    /** Texte narratif 80-120 mots ou texte fallback. */
    #[ORM\Column(type: 'text')]
    private string $content;

    /** Version du modèle IA ('mistral-small-latest' ou '' si is_fallback=true). */
    #[ORM\Column(length: 64)]
    private string $modelVersion;

    /** Horodatage UTC de génération. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $generatedAt;

    /** true si Mistral était KO — badge émeraude non affiché côté présentation (INV-2). */
    #[ORM\Column]
    private bool $isFallback;

    public function __construct(
        Uuid $id,
        Uuid $briefId,
        string $content,
        string $modelVersion,
        \DateTimeImmutable $generatedAt,
        bool $isFallback,
    ) {
        $this->id = $id;
        $this->briefId = $briefId;
        $this->content = $content;
        $this->modelVersion = $modelVersion;
        $this->generatedAt = $generatedAt;
        $this->isFallback = $isFallback;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBriefId(): Uuid
    {
        return $this->briefId;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getModelVersion(): string
    {
        return $this->modelVersion;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function isFallback(): bool
    {
        return $this->isFallback;
    }
}
