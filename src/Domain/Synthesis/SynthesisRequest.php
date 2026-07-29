<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Value Object — Requête de synthèse IA (US-010 + US-011).
 *
 * Immuable. Porte l'URL source de l'article à synthétiser et le niveau de
 * synthèse demandé.
 *
 * La validation SSRF et le format URL sont vérifiés par SynthesisService (Application)
 * avant toute construction d'un objet SynthesisRequest valide.
 *
 * Rétrocompatibilité (US-011) :
 * - Le paramètre `$level` est optionnel et vaut `SynthesisLevel::CONCISE` par défaut.
 * - Les clients existants sans `level` obtiennent le comportement Concise.
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
final class SynthesisRequest
{
    /**
     * @param string $url URL source de l'article — doit être http(s), jamais IP privée (RFC 1918)
     * @param SynthesisLevel $level Niveau de synthèse demandé (défaut : CONCISE)
     */
    public function __construct(
        public readonly string $url,
        public readonly SynthesisLevel $level = SynthesisLevel::CONCISE,
    ) {
    }
}
