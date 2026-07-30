<?php

declare(strict_types=1);

namespace App\Application\Synthesis;

use App\Domain\Synthesis\InvalidSynthesisUrlException;

/**
 * Service Application — Normalisation/canonicalisation des URLs (US-012 T-012-01).
 *
 * Algorithme de canonicalisation :
 *   1. Suppression des caractères de contrôle (\r, \n, \0) — anti key-injection Redis
 *   2. Parse de l'URL (scheme + host obligatoires)
 *   3. Lowercase du scheme et du host
 *   4. Reconstruction : scheme://host[:port]/path
 *   5. Tri alphabétique des query params (ksort)
 *   6. Suppression du fragment (# et tout ce qui suit)
 *   7. Validation finale via filter_var(FILTER_VALIDATE_URL)
 *
 * Exemples :
 *   "HTTPS://TechCrunch.COM/article?z=1&a=2"
 *     → "https://techcrunch.com/article?a=2&z=1"
 *
 *   "https://example.com/path#anchor"
 *     → "https://example.com/path"
 *
 *   "https://example.com/article?z=3&a=1"
 *     → "https://example.com/article?a=1&z=3"
 *
 * Sécurité :
 *   - Rejet strict des URLs contenant \r, \n ou \0 (caractères nuls) — HTTP injection
 *   - Rejet des URLs invalides après normalisation (HTTP 422 côté API)
 *   - Aucun PII loggué (url_hash SHA-256 uniquement)
 *
 * Deptrac : Application → Domain (InvalidSynthesisUrlException).
 */
final class UrlNormalizer
{
    /**
     * Normalise et canonicalise une URL pour maximiser les cache hits.
     *
     * URLs équivalentes après normalisation produiront le même SHA-256 dans
     * SynthesisService::buildCacheKey(), garantissant une clé cache unique
     * indépendamment de la casse ou de l'ordre des paramètres.
     *
     * @param string $url URL brute fournie par le client
     *
     * @throws InvalidSynthesisUrlException si l'URL contient des caractères de contrôle
     *                                      ou reste invalide après normalisation
     *
     * @return string URL normalisée (lowercase scheme+host, query params triés, pas de fragment)
     */
    public function normalize(string $url): string
    {
        // ── 1. Rejet strict des caractères de contrôle (anti key-injection Redis) ──
        // \r, \n permettraient d'injecter des commandes Redis via la clé de cache
        // \0 (null byte) permet de tronquer les chaînes dans certains contextes
        if (
            str_contains($url, "\r")
            || str_contains($url, "\n")
            || str_contains($url, "\0")
        ) {
            throw new InvalidSynthesisUrlException('URL invalide — vérifiez le format de l\'adresse');
        }

        // ── 2. Parse de l'URL ─────────────────────────────────────────────────────
        $parsed = parse_url($url);

        if (
            false === $parsed
            || !isset($parsed['scheme'])
            || !isset($parsed['host'])
        ) {
            throw new InvalidSynthesisUrlException('URL invalide — vérifiez le format de l\'adresse');
        }

        // ── 3. Lowercase du scheme et du host ────────────────────────────────────
        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host']);

        // ── 4. Reconstruction : scheme://host[:port]/path ─────────────────────────
        $normalized = $scheme . '://' . $host;

        if (isset($parsed['port'])) {
            $normalized .= ':' . $parsed['port'];
        }

        // Chemin : normaliser slash final et encoder proprement
        $path = $parsed['path'] ?? '/';
        $normalized .= ('' === $path ? '/' : $path);

        // ── 5. Tri alphabétique des query params ──────────────────────────────────
        if (isset($parsed['query']) && '' !== $parsed['query']) {
            parse_str($parsed['query'], $queryParams);
            ksort($queryParams);
            $sortedQuery = http_build_query($queryParams);

            if ('' !== $sortedQuery) {
                $normalized .= '?' . $sortedQuery;
            }
        }

        // ── 6. Fragment ignoré (pas ajouté à l'URL normalisée) ───────────────────
        // $parsed['fragment'] est intentionnellement omis

        // ── 7. Validation finale ──────────────────────────────────────────────────
        if (false === filter_var($normalized, \FILTER_VALIDATE_URL)) {
            throw new InvalidSynthesisUrlException('URL invalide — vérifiez le format de l\'adresse');
        }

        return $normalized;
    }
}
