<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Value Object — Contenu d'article récupéré depuis son URL.
 *
 * Retourné par ArticleContentFetcherInterface::fetchContent().
 *
 * `isPartial` indique si le contenu est incomplet (paywall, accès limité).
 * Dans ce cas, SynthesisService produit une synthèse avec la mention
 * "Contenu partiel — accès limité à la source" (US-010 scénario alternatif 1).
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
final class FetchedContent
{
    /**
     * @param string $text Contenu textuel de l'article (HTML tags strippés)
     * @param bool $isPartial true si accès limité (paywall ou contenu tronqué)
     */
    public function __construct(
        public readonly string $text,
        public readonly bool $isPartial = false,
    ) {
    }
}
