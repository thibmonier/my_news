<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Port Domaine — Fetcher de contenu d'article depuis une URL.
 *
 * Abstraction du client HTTP de récupération de contenu pour permettre
 * le test unitaire de SynthesisService sans appel réseau réel.
 *
 * Implémenté par App\Infrastructure\Synthesis\Http\HttpArticleContentFetcher.
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 *
 * Contrat :
 * - Retourne le contenu textuel de l'article (HTML extrait ou texte brut)
 * - `$isPartial` indique si l'accès au contenu était limité (paywall)
 * - Timeout 10s recommandé côté implémentation
 */
interface ArticleContentFetcherInterface
{
    /**
     * Fetche le contenu textuel d'un article depuis son URL.
     *
     * @param string $url URL de l'article (déjà validée SSRF par SynthesisService)
     *
     * @throws SynthesisUnavailableException si l'article est inaccessible (timeout, 4xx/5xx)
     *
     * @return FetchedContent Contenu textuel de l'article + indicateur isPartial
     */
    public function fetchContent(string $url): FetchedContent;
}
