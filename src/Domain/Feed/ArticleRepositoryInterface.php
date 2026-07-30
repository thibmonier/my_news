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
     * Aucune exception levée en cas de doublon SHA-256.
     *
     * @param ArticleDTO $dto Données de l'article à persister
     *
     * @return string|null UUID v4 de l'article inséré, ou null si ignoré (doublon SHA-256)
     */
    public function saveIgnoringDuplicate(ArticleDTO $dto): ?string;

    /**
     * Recherche les articles non-dupliqués avec un SimHash proche dans une fenêtre ±2h.
     *
     * Utilise BIT_COUNT((title_simhash # :simhash)::bit(64)) en PostgreSQL 14+.
     * Filtre temporel : ABS(EXTRACT(EPOCH FROM published_at - :pub)) <= 7200.
     * Exclut les articles déjà marqués is_duplicate = TRUE.
     *
     * @param int $simhash SimHash 64 bits de l'article entrant
     * @param \DateTimeImmutable $publishedAt Date de publication de l'article entrant
     * @param int $threshold Seuil de distance de Hamming (ex. 3)
     *
     * @return list<array{id: string, title: string, simhash: int}>
     */
    public function findPotentialDuplicates(int $simhash, \DateTimeImmutable $publishedAt, int $threshold): array;

    /**
     * Marque un article comme doublon sémantique d'un article existant.
     *
     * Met à jour : title_simhash = $simhash, is_duplicate = TRUE, duplicate_of = $duplicateOfId.
     * L'article reste en base (jamais supprimé — traçabilité garantie).
     *
     * @param string $articleId UUID de l'article à marquer
     * @param int $simhash SimHash calculé de cet article
     * @param string $duplicateOfId UUID de l'article original
     */
    public function markAsDuplicate(string $articleId, int $simhash, string $duplicateOfId): void;

    /**
     * Met à jour uniquement le title_simhash d'un article non-doublon.
     *
     * Appelé après insertion d'un article dont aucun doublon n'a été trouvé.
     *
     * @param string $articleId UUID de l'article à mettre à jour
     * @param int $simhash SimHash 64 bits calculé
     */
    public function updateTitleSimHash(string $articleId, int $simhash): void;

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
