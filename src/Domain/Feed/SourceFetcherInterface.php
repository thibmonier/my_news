<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Port primaire — Récupération et parsing d'un flux RSS/Atom.
 *
 * Définit le contrat que tout adaptateur d'ingestion de flux doit respecter.
 * L'implémentation concrète (FeedIo, etc.) réside dans Infrastructure.
 *
 * Constitution §4 : interfaces de repository/port dans le Domain, implémentations dans Infrastructure.
 * Deptrac Domain:[] — aucune dépendance framework dans ce fichier.
 */
interface SourceFetcherInterface
{
    /**
     * Récupère et parse le flux de la source donnée.
     *
     * @param Source $source Source dont l'URL est à interroger
     *
     * @throws \RuntimeException Si le flux est inaccessible ou malformé (HTTP != 200 ou XML invalide)
     *
     * @return list<ArticleDTO> Articles parsés ; liste vide si aucun article disponible
     */
    public function fetch(Source $source): array;
}
