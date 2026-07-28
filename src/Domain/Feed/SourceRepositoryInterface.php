<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Port secondaire — Persistence des Sources.
 *
 * Définit le contrat de repository pour les sources RSS/Atom.
 * L'implémentation concrète réside dans Infrastructure (Doctrine).
 *
 * Constitution §4 : interfaces dans le Domain, implémentations dans Infrastructure (DIP).
 * Deptrac Domain:[] — aucune dépendance framework dans ce fichier.
 */
interface SourceRepositoryInterface
{
    /**
     * Trouve une source par son identifiant UUID.
     *
     * @param non-empty-string $id UUID v4
     */
    public function findById(string $id): ?Source;

    /**
     * Retourne toutes les sources avec status='active'.
     *
     * @return list<Source>
     */
    public function findAllActive(): array;

    /**
     * Met à jour last_fetched_at pour la source identifiée.
     *
     * @param non-empty-string $sourceId UUID v4
     */
    public function updateLastFetchedAt(string $sourceId, \DateTimeImmutable $at): void;

    /**
     * Met à jour last_error_at pour la source identifiée.
     *
     * @param non-empty-string $sourceId UUID v4
     */
    public function updateLastErrorAt(string $sourceId, \DateTimeImmutable $at): void;
}
