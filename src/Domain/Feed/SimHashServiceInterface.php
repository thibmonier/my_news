<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Port — Calcul SimHash 64 bits sur le titre d'un article.
 *
 * Barrière secondaire de déduplication (après SHA-256 URL) permettant
 * de détecter des articles sémantiquement proches publiés par des sources différentes.
 *
 * Constitution §4 : interfaces dans le Domain, implémentations dans Infrastructure (DIP).
 * Deptrac Domain:[] — aucune dépendance framework dans ce fichier.
 *
 * @see \App\Infrastructure\Feed\SimHash\SimHashService Implémentation FNV1a-64
 */
interface SimHashServiceInterface
{
    /**
     * Calcule le SimHash 64 bits du titre normalisé.
     *
     * Normalisation appliquée :
     * - Mise en minuscules (mb_strtolower, UTF-8)
     * - Tokenisation sur espaces et ponctuation (preg_split '/[\s\p{P}]+/u')
     * - Suppression des stopwords FR/EN
     * - Hash FNV1a-64 par token, votes bit par bit, signe → bit SimHash
     *
     * @param string $title Titre brut de l'article (peut être vide)
     *
     * @throws \RuntimeException si la normalisation échoue de façon inattendue
     *                           (attrapée par le handler — ne bloque pas l'ingestion)
     *
     * @return int|null SimHash 64 bits signé (PHP int), null si titre vide ou
     *                  normalisé à une liste vide de tokens
     */
    public function compute(string $title): ?int;

    /**
     * Distance de Hamming entre deux SimHash 64 bits.
     *
     * Equivalent de BIT_COUNT(a XOR b) — nombre de bits différents.
     * Un seuil de ≤ 3 bits indique deux titres sémantiquement proches.
     *
     * @param int $a Premier SimHash 64 bits
     * @param int $b Second SimHash 64 bits
     *
     * @return int Nombre de bits différents (0–64)
     */
    public function distance(int $a, int $b): int;
}
