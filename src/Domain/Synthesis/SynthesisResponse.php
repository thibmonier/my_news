<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Value Object — Réponse de synthèse IA générée par Mistral.
 *
 * Immuable. Contient :
 * - `content`     : condensé textuel (~200 mots), préfixé "BRIEFLY AI:"
 * - `keyPoints`   : exactement 3 points clés numérotés 01/02/03
 * - `sources`     : au moins une source nommée
 * - `originalUrl` : URL de l'article source (pour le lien "OUVRIR L'ORIGINAL")
 * - `isPartial`   : true si le contenu source était partiellement accessible (paywall)
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
final class SynthesisResponse
{
    /**
     * @param string $content Condensé IA (~200 mots), préfixé "BRIEFLY AI:"
     * @param string[] $keyPoints Tableau de 3 points clés (numérotés "01 ...", "02 ...", "03 ...")
     * @param string[] $sources Tableau de sources citées (noms ou domaines)
     * @param string $originalUrl URL de l'article synthétisé
     * @param bool $isPartial true si accès partiel au contenu source (paywall)
     */
    public function __construct(
        public readonly string $content,
        public readonly array $keyPoints,
        public readonly array $sources,
        public readonly string $originalUrl,
        public readonly bool $isPartial = false,
    ) {
    }
}
