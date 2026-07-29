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
 * US-021 : ajout findPaginated, countForListing, findByUrl, save, updateStatus, softDelete.
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
            ->findBy(
                criteria: ['status' => SourceStatus::Active, 'deletedAt' => null],
                orderBy: ['name' => 'ASC'],
            );

        return array_map(
            static fn (DoctrineSourceEntity $e): Source => $e->toDomainEntity(),
            $entities,
        );
    }

    /**
     * @return list<Source>
     */
    public function findPaginated(int $page, int $perPage, ?string $query = null): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('s')
            ->from(DoctrineSourceEntity::class, 's')
            ->where('s.deletedAt IS NULL')
            ->orderBy('s.name', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (null !== $query && '' !== $query) {
            $qb->andWhere('LOWER(s.name) LIKE LOWER(:query) OR LOWER(s.url) LIKE LOWER(:query)')
                ->setParameter('query', '%' . $query . '%');
        }

        /** @var list<DoctrineSourceEntity> $entities */
        $entities = $qb->getQuery()->getResult();

        return array_map(
            static fn (DoctrineSourceEntity $e): Source => $e->toDomainEntity(),
            $entities,
        );
    }

    public function countForListing(?string $query = null): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(DoctrineSourceEntity::class, 's')
            ->where('s.deletedAt IS NULL');

        if (null !== $query && '' !== $query) {
            $qb->andWhere('LOWER(s.name) LIKE LOWER(:query) OR LOWER(s.url) LIKE LOWER(:query)')
                ->setParameter('query', '%' . $query . '%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findByUrl(string $url): ?Source
    {
        $entity = $this->em
            ->getRepository(DoctrineSourceEntity::class)
            ->findOneBy(['url' => $url]);

        return $entity?->toDomainEntity();
    }

    public function save(Source $source): void
    {
        $uuid = Uuid::fromString($source->getId());
        $entity = $this->em->getRepository(DoctrineSourceEntity::class)->find($uuid);

        if (null === $entity) {
            // Création
            $entity = new DoctrineSourceEntity(
                id: $uuid,
                name: $source->getName(),
                url: $source->getUrl(),
                feedType: $source->getFeedType(),
                status: $source->getStatus(),
            );
            $entity->setFetchIntervalMinutes($source->getFetchIntervalMinutes());
            $this->em->persist($entity);
        } else {
            // Mise à jour
            $entity->updateFromDomain($source);
        }

        $this->em->flush();
    }

    public function updateStatus(string $sourceId, SourceStatus $status): void
    {
        $this->em->createQuery(
            'UPDATE ' . DoctrineSourceEntity::class . ' s
             SET s.status = :status
             WHERE s.id = :id',
        )
            ->setParameter('status', $status->value)
            ->setParameter('id', Uuid::fromString($sourceId))
            ->execute();
    }

    public function softDelete(string $sourceId): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->em->createQuery(
            'UPDATE ' . DoctrineSourceEntity::class . ' s
             SET s.status = :status, s.deletedAt = :deletedAt
             WHERE s.id = :id',
        )
            ->setParameter('status', SourceStatus::Deleted->value)
            ->setParameter('deletedAt', $now)
            ->setParameter('id', Uuid::fromString($sourceId))
            ->execute();
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
