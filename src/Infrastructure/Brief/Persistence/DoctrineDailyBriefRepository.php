<?php

declare(strict_types=1);

namespace App\Infrastructure\Brief\Persistence;

use App\Domain\Brief\BriefStory;
use App\Domain\Brief\DailyBrief;
use App\Domain\Brief\DailyBriefRepositoryInterface;
use App\Domain\Brief\DailyBriefStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter Doctrine/ORM — Implémentation de DailyBriefRepositoryInterface.
 *
 * Upsert idempotent :
 * - Si le brief existe déjà pour la date → mise à jour du statut et updated_at
 * - Les BriefStories sont supprimées (cascade) puis réinsérées dans la même transaction
 *
 * Couche Infrastructure : dépend de Doctrine ORM et du Domain.
 * Deptrac : Infrastructure:[Domain, Application].
 */
final class DoctrineDailyBriefRepository implements DailyBriefRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findLatest(): ?DailyBrief
    {
        /** @var DoctrineDailyBriefEntity|null $entity */
        $entity = $this->entityManager
            ->getRepository(DoctrineDailyBriefEntity::class)
            ->findOneBy(
                ['status' => DailyBriefStatus::Ready],
                ['date' => 'DESC'],
            );

        if (null === $entity) {
            return null;
        }

        return $this->toDomainEntity($entity);
    }

    public function findForDate(\DateTimeImmutable $date): ?DailyBrief
    {
        $dateString = $date->format('Y-m-d');

        /** @var DoctrineDailyBriefEntity|null $entity */
        $entity = $this->entityManager
            ->getRepository(DoctrineDailyBriefEntity::class)
            ->findOneBy(['date' => new \DateTimeImmutable($dateString, new \DateTimeZone('UTC'))]);

        if (null === $entity) {
            return null;
        }

        return $this->toDomainEntity($entity);
    }

    /**
     * Crée ou met à jour le DailyBrief dans une transaction atomique.
     *
     * Idempotence : si un brief existe déjà pour cette date, ses BriefStories
     * sont supprimées (orphanRemoval) puis réinsérées avec les nouveaux scores.
     */
    public function upsertForToday(DailyBrief $brief): void
    {
        $dateStr = $brief->getDate()->format('Y-m-d');
        $dateObj = new \DateTimeImmutable($dateStr, new \DateTimeZone('UTC'));

        /** @var DoctrineDailyBriefEntity|null $entity */
        $entity = $this->entityManager
            ->getRepository(DoctrineDailyBriefEntity::class)
            ->findOneBy(['date' => $dateObj]);

        if (null === $entity) {
            $entity = new DoctrineDailyBriefEntity(
                id: Uuid::fromString($brief->getId()),
                date: $dateObj,
                status: $brief->getStatus(),
                updatedAt: $brief->getUpdatedAt(),
            );
            $this->entityManager->persist($entity);
        } else {
            // Mise à jour du statut et updated_at
            $entity->setStatus($brief->getStatus());
            $entity->setUpdatedAt($brief->getUpdatedAt());

            // Supprimer les stories existantes (orphanRemoval les efface en cascade)
            $entity->clearStories();
            $this->entityManager->flush(); // Flush intermédiaire pour déclencher les DELETE
        }

        // Réinsérer les nouvelles stories
        foreach ($brief->getStories() as $story) {
            $storyEntity = new DoctrineBriefStoryEntity(
                id: Uuid::fromString($story->getId()),
                brief: $entity,
                articleId: Uuid::fromString($story->getArticleId()),
                position: $story->getPosition(),
                selectionScore: $story->getSelectionScore(),
            );
            $entity->addStory($storyEntity);
            $this->entityManager->persist($storyEntity);
        }

        $this->entityManager->flush();
    }

    /**
     * Convertit l'entité Doctrine en entité Domain (anti-corruption layer).
     */
    private function toDomainEntity(DoctrineDailyBriefEntity $entity): DailyBrief
    {
        $stories = array_map(
            static fn (DoctrineBriefStoryEntity $s): BriefStory => new BriefStory(
                id: $s->getId()->toRfc4122(),
                briefId: $s->getBrief()->getId()->toRfc4122(),
                articleId: $s->getArticleId()->toRfc4122(),
                position: $s->getPosition(),
                selectionScore: $s->getSelectionScore(),
            ),
            $entity->getStories()->toArray(),
        );

        return new DailyBrief(
            id: $entity->getId()->toRfc4122(),
            date: $entity->getDate(),
            status: $entity->getStatus(),
            updatedAt: $entity->getUpdatedAt(),
            stories: array_values($stories),
        );
    }
}
