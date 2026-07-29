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
     * Retourne toutes les sources avec status='active' et deleted_at IS NULL.
     *
     * @return list<Source>
     */
    public function findAllActive(): array;

    /**
     * Retourne les sources paginées (hors soft-deleted).
     * Filtre optionnel ILIKE sur name et url si $query non nul.
     *
     * @return list<Source>
     */
    public function findPaginated(int $page, int $perPage, ?string $query = null): array;

    /**
     * Compte les sources hors soft-deleted (pour la pagination).
     * Filtre optionnel ILIKE sur name et url si $query non nul.
     */
    public function countForListing(?string $query = null): int;

    /**
     * Trouve une source par son URL exacte (pour contrôle d'unicité).
     */
    public function findByUrl(string $url): ?Source;

    /**
     * Persiste une source (création ou mise à jour).
     * Identifié par l'ID : crée si inexistant, met à jour sinon.
     */
    public function save(Source $source): void;

    /**
     * Met à jour le statut d'une source.
     *
     * @param non-empty-string $sourceId UUID v4
     */
    public function updateStatus(string $sourceId, SourceStatus $status): void;

    /**
     * Soft-delete : status=deleted, deleted_at=now().
     *
     * @param non-empty-string $sourceId UUID v4
     */
    public function softDelete(string $sourceId): void;

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
