<?php

declare(strict_types=1);

namespace App\Infrastructure\Brief\Persistence;

use App\Domain\Brief\DailyBriefSummaryRepositoryInterface;
use App\Domain\Brief\FeaturedSummaryDTO;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter Infrastructure — Persistance Doctrine des synthèses narratives (US-006).
 *
 * Implémente DailyBriefSummaryRepositoryInterface (port Domain).
 *
 * Mapping Domain → Infrastructure :
 * - FeaturedSummaryDTO (Domain VO — PHP pur) ↔ DoctrineDailyBriefSummaryEntity (ORM)
 *
 * UPSERT : si une entrée existe déjà pour ce brief_id (contrainte UNIQUE),
 * elle est supprimée puis remplacée (idempotence du batch).
 *
 * Deptrac : Infrastructure → Domain (FeaturedSummaryDTO, DailyBriefSummaryRepositoryInterface).
 */
final class DoctrineDailyBriefSummaryRepository implements DailyBriefSummaryRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function findByBriefId(string $briefId): ?FeaturedSummaryDTO
    {
        try {
            $briefUuid = Uuid::fromString($briefId);
        } catch (\Throwable) {
            return null;
        }

        $entity = $this->entityManager
            ->getRepository(DoctrineDailyBriefSummaryEntity::class)
            ->findOneBy(['briefId' => $briefUuid]);

        return null !== $entity ? $this->toDomain($entity) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function findLatest(): ?FeaturedSummaryDTO
    {
        $entity = $this->entityManager
            ->getRepository(DoctrineDailyBriefSummaryEntity::class)
            ->createQueryBuilder('s')
            ->orderBy('s.generatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$entity instanceof DoctrineDailyBriefSummaryEntity) {
            return null;
        }

        return $this->toDomain($entity);
    }

    /**
     * {@inheritDoc}
     *
     * UPSERT : supprime l'entrée existante pour ce brief_id avant d'en créer une nouvelle.
     * Garantit l'idempotence du batch (relance possible sans doublon).
     */
    public function save(FeaturedSummaryDTO $summary): void
    {
        try {
            $briefUuid = Uuid::fromString($summary->briefId);
        } catch (\Throwable) {
            return; // briefId invalide — skip silencieux
        }

        // Suppression de l'entrée existante (UPSERT manuel)
        $existing = $this->entityManager
            ->getRepository(DoctrineDailyBriefSummaryEntity::class)
            ->findOneBy(['briefId' => $briefUuid]);

        if (null !== $existing) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }

        $entity = new DoctrineDailyBriefSummaryEntity(
            id: Uuid::v4(),
            briefId: $briefUuid,
            content: $summary->content,
            modelVersion: $summary->modelVersion,
            generatedAt: $summary->generatedAt,
            isFallback: $summary->isFallback,
        );

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    /**
     * Convertit une entité Doctrine en Value Object Domain.
     */
    private function toDomain(DoctrineDailyBriefSummaryEntity $entity): FeaturedSummaryDTO
    {
        return new FeaturedSummaryDTO(
            briefId: $entity->getBriefId()->toRfc4122(),
            content: $entity->getContent(),
            modelVersion: $entity->getModelVersion(),
            generatedAt: $entity->getGeneratedAt(),
            isFallback: $entity->isFallback(),
        );
    }
}
