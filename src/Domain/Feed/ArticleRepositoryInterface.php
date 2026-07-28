<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Port secondaire — Persistence des Articles ingérés.
 *
 * Définit le contrat de repository pour les articles RSS.
 * L'implémentation concrète réside dans Infrastructure (Doctrine + DBAL).
 *
 * Constitution §4 : interfaces dans le Domain, implémentations dans Infrastructure (DIP).
 * Deptrac Domain:[] — aucune dépendance framework dans ce fichier.
 */
interface ArticleRepositoryInterface
{
    /**
     * Insère l'article si son content_hash n'existe pas déjà.
     *
     * Utilise INSERT … ON CONFLICT (content_hash) DO NOTHING côté SQL.
     * Aucune exception levée en cas de doublon.
     *
     * @param ArticleDTO $dto Données de l'article à persister
     *
     * @return bool true si l'article a été inséré, false si ignoré (doublon)
     */
    public function saveIgnoringDuplicate(ArticleDTO $dto): bool;

    /**
     * Retourne une page d'articles avec le nom de leur source, triés par published_at DESC.
     *
     * @param positive-int $page Numéro de page (1-indexé)
     * @param positive-int $perPage Nombre d'articles par page (50 pour la liste admin)
     *
     * @return list<array{id: string, title: string, url: string, contentHash: string, publishedAt: \DateTimeImmutable, sourceName: string}>
     */
    public function findPaginatedWithSourceName(int $page, int $perPage): array;

    /**
     * Retourne le nombre total d'articles en base.
     *
     * @return non-negative-int
     */
    public function countAll(): int;
}
