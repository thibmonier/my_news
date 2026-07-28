<?php

declare(strict_types=1);

namespace App\Infrastructure\Synthesis\Persistence;

use App\Domain\Synthesis\SynthesisResult;
use App\Domain\Synthesis\SynthesisResultRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter Infrastructure — Persistance des résultats de synthèse IA via Doctrine.
 *
 * Implémente SynthesisResultRepositoryInterface (port Domain).
 *
 * Mapping Domain → Infrastructure :
 * - SynthesisResult (Domain entity — PHP pur) → DoctrineSynthesisResultEntity (ORM entity)
 * - Traduction des UUIDs string → Symfony\Component\Uid\Uuid (Doctrine type)
 *
 * Deptrac : Infrastructure → Domain (SynthesisResult, SynthesisResultRepositoryInterface).
 */
final class DoctrineSynthesisResultRepository implements SynthesisResultRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Persiste un résultat de synthèse IA en base de données.
     *
     * Traçabilité analytics Sprint 1 : appel systématique après chaque génération Mistral.
     */
    public function save(SynthesisResult $result): void
    {
        $entity = new DoctrineSynthesisResultEntity(
            id: Uuid::fromString($result->getId()),
            urlHash: $result->getUrlHash(),
            level: $result->getLevel(),
            content: $result->getContent(),
            keyPoints: $result->getKeyPoints(),
            sources: $result->getSources(),
            createdAt: $result->getCreatedAt(),
        );

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }
}
