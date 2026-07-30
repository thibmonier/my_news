<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Persistence;

use App\Domain\User\Email;
use App\Domain\User\User;
use App\Domain\User\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * Adapter — Repository Doctrine pour les utilisateurs.
 *
 * Implémente UserRepositoryInterface (port Domain) via Doctrine ORM.
 * Convertit systématiquement Domain ↔ DoctrineUserEntity (anti-corruption layer).
 *
 * Constitution §4 : accès Doctrine uniquement dans Infrastructure.
 * Deptrac : Infrastructure → Domain + Application.
 */
final class DoctrineUserRepository implements UserRepositoryInterface
{
    /** @var EntityRepository<DoctrineUserEntity> */
    private readonly EntityRepository $repository;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->repository = $this->entityManager->getRepository(DoctrineUserEntity::class);
    }

    public function save(User $user): void
    {
        $existing = $this->repository->find($user->getId());

        if (null !== $existing) {
            // Mise à jour — recréer à partir du domaine
            // Note : En DDD strict, on trackrait les changements via des méthodes spécifiques.
            // Pour le Sprint 1, on remplace l'entité Doctrine.
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }

        $entity = DoctrineUserEntity::fromDomainEntity($user);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByEmail(Email $email): ?User
    {
        $entity = $this->repository->findOneBy(['email' => $email->getValue()]);

        return $entity?->toDomainEntity();
    }

    public function emailExists(Email $email): bool
    {
        return $this->repository->count(['email' => $email->getValue()]) > 0;
    }

    public function findById(string $id): ?User
    {
        $entity = $this->repository->find($id);

        return $entity?->toDomainEntity();
    }

    public function findByEmailPendingToken(string $token): ?User
    {
        $entity = $this->repository->findOneBy(['emailPendingToken' => $token]);

        return $entity?->toDomainEntity();
    }

    /**
     * Trouve l'entité Doctrine directement (usage interne Infrastructure/Presentation
     * pour l'intégration avec Symfony Security).
     *
     * N'expose PAS via UserRepositoryInterface (évite la fuite d'infrastructure dans Domain).
     */
    public function findEntityByEmail(string $email): ?DoctrineUserEntity
    {
        return $this->repository->findOneBy(['email' => mb_strtolower(trim($email))]);
    }
}
