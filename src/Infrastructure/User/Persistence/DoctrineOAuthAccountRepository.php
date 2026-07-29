<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Persistence;

use App\Domain\User\OAuthAccount;
use App\Domain\User\OAuthAccountRepositoryInterface;
use App\Domain\User\OAuthProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * Adapter — Repository Doctrine pour les comptes OAuth.
 *
 * Implémente OAuthAccountRepositoryInterface (port Domain) via Doctrine ORM.
 * Convertit systématiquement Domain ↔ DoctrineOAuthAccountEntity (anti-corruption layer).
 *
 * Constitution §4 : accès Doctrine uniquement dans Infrastructure.
 * Deptrac : Infrastructure → Domain + Application.
 */
final class DoctrineOAuthAccountRepository implements OAuthAccountRepositoryInterface
{
    /** @var EntityRepository<DoctrineOAuthAccountEntity> */
    private readonly EntityRepository $repository;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->repository = $this->entityManager->getRepository(DoctrineOAuthAccountEntity::class);
    }

    public function save(OAuthAccount $account): void
    {
        $entity = DoctrineOAuthAccountEntity::fromDomainEntity($account);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByProviderAndId(OAuthProvider $provider, string $providerId): ?OAuthAccount
    {
        $entity = $this->repository->findOneBy([
            'provider' => $provider->getValue(),
            'providerId' => $providerId,
        ]);

        return $entity?->toDomainEntity();
    }

    public function findByUserIdAndProvider(string $userId, OAuthProvider $provider): ?OAuthAccount
    {
        $entity = $this->repository->findOneBy([
            'userId' => $userId,
            'provider' => $provider->getValue(),
        ]);

        return $entity?->toDomainEntity();
    }
}
