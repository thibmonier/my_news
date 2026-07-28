<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Value Object — Requête de synthèse IA.
 *
 * Immuable. Porte l'URL source de l'article à synthétiser.
 * La validation SSRF et le format URL sont vérifiés par SynthesisService (Application)
 * avant toute construction d'un objet SynthesisRequest valide.
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
final class SynthesisRequest
{
    /**
     * @param string $url URL source de l'article — doit être http(s), jamais IP privée (RFC1918)
     */
    public function __construct(
        public readonly string $url,
    ) {
    }
}
