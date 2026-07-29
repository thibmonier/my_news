<?php

declare(strict_types=1);

namespace App\Infrastructure\Summary\Persistence;

use App\Domain\Summary\ArticleSummary;
use App\Domain\Summary\ArticleSummaryRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter Infrastructure — Persistance Doctrine des condensés IA (US-004).
 *
 * Implémente ArticleSummaryRepositoryInterface (port Domain).
 *
 * Mapping Domain → Infrastructure :
 * - ArticleSummary (Domain VO — PHP pur) ↔ DoctrineArticleSummaryEntity (ORM entity)
 *
 * Deptrac : Infrastructure → Domain (ArticleSummary, ArticleSummaryRepositoryInterface).
 */
final class DoctrineArticleSummaryRepository implements ArticleSummaryRepositoryInterface
{
    /** TTL logique = 24h (cohérent avec le TTL Redis). */
    private const EXPIRES_AFTER_SECONDS = 86400;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function findByArticleId(string $articleId): ?ArticleSummary
    {
        try {
            $articleUuid = Uuid::fromString($articleId);
        } catch (\Throwable) {
            return null;
        }

        $entity = $this->entityManager
            ->getRepository(DoctrineArticleSummaryEntity::class)
            ->findOneBy(['articleId' => $articleUuid]);

        if (null === $entity) {
            return null;
        }

        return $this->toDomain($entity);
    }

    /**
     * {@inheritDoc}
     */
    public function save(ArticleSummary $summary): void
    {
        try {
            $articleUuid = Uuid::fromString($summary->articleId);
        } catch (\Throwable) {
            // articleId invalide — on ignore silencieusement (pas de blocage fonctionnel)
            return;
        }

        $cachedAt = $summary->createdAt;
        $expiresAt = $cachedAt->add(new \DateInterval('PT' . self::EXPIRES_AFTER_SECONDS . 'S'));

        $entity = new DoctrineArticleSummaryEntity(
            id: Uuid::v4(),
            articleId: $articleUuid,
            keyPoints: $summary->keyPoints,
            modelVersion: $summary->modelVersion,
            isDegraded: $summary->isDegraded,
            degradedContent: $summary->isDegraded ? $summary->degradedContent : null,
            cachedAt: $cachedAt,
            expiresAt: $expiresAt,
        );

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    /**
     * Convertit une entité Doctrine en Value Object Domain.
     */
    private function toDomain(DoctrineArticleSummaryEntity $entity): ArticleSummary
    {
        return new ArticleSummary(
            articleId: $entity->getArticleId()->toRfc4122(),
            keyPoints: $entity->getKeyPoints(),
            modelVersion: $entity->getModelVersion(),
            createdAt: $entity->getCachedAt(),
            isDegraded: $entity->isDegraded(),
            degradedContent: $entity->getDegradedContent() ?? '',
        );
    }
}
