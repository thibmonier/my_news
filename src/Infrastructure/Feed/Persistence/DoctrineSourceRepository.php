<?php

declare(strict_types=1);

namespace App\Infrastructure\Feed\Persistence;

use App\Domain\Feed\Source;
use App\Domain\Feed\SourceRepositoryInterface;
use App\Domain\Feed\SourceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter Doctrine — Implémentation de SourceRepositoryInterface.
 *
 * Couche Infrastructure : dépend de Doctrine et du Domain.
 * Deptrac : Infrastructure:[Domain, Application].
 *
 * Les méthodes de mise à jour utilisent des requêtes DQL UPDATE
 * (plus efficaces qu'un load+flush pour des champs isolés).
 */
final class DoctrineSourceRepository implements SourceRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function findById(string $id): ?Source
    {
        $entity = $this->em
            ->getRepository(DoctrineSourceEntity::class)
            ->find(Uuid::fromString($id));

        return $entity?->toDomainEntity();
    }

    /**
     * @return list<Source>
     */
    public function findAllActive(): array
    {
        /** @var list<DoctrineSourceEntity> $entities */
        $entities = $this->em
            ->getRepository(DoctrineSourceEntity::class)
            ->findBy(['status' => SourceStatus::Active]);

        return array_map(
            static fn (DoctrineSourceEntity $e): Source => $e->toDomainEntity(),
            $entities,
        );
    }

    public function updateLastFetchedAt(string $sourceId, \DateTimeImmutable $at): void
    {
        $this->em->createQuery(
            'UPDATE ' . DoctrineSourceEntity::class . ' s
             SET s.lastFetchedAt = :at
             WHERE s.id = :id',
        )
            ->setParameter('at', $at)
            ->setParameter('id', Uuid::fromString($sourceId))
            ->execute();
    }

    public function updateLastErrorAt(string $sourceId, \DateTimeImmutable $at): void
    {
        $this->em->createQuery(
            'UPDATE ' . DoctrineSourceEntity::class . ' s
             SET s.lastErrorAt = :at
             WHERE s.id = :id',
        )
            ->setParameter('at', $at)
            ->setParameter('id', Uuid::fromString($sourceId))
            ->execute();
    }
}
