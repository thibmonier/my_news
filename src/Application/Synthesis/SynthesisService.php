<?php

declare(strict_types=1);

namespace App\Application\Synthesis;

use App\Domain\Synthesis\ArticleContentFetcherInterface;
use App\Domain\Synthesis\InvalidSynthesisUrlException;
use App\Domain\Synthesis\MistralClientInterface;
use App\Domain\Synthesis\SynthesisCacheInterface;
use App\Domain\Synthesis\SynthesisLevel;
use App\Domain\Synthesis\SynthesisRequest;
use App\Domain\Synthesis\SynthesisResponse;
use App\Domain\Synthesis\SynthesisResponseWithCacheStatus;
use App\Domain\Synthesis\SynthesisResult;
use App\Domain\Synthesis\SynthesisResultRepositoryInterface;
use App\Domain\Synthesis\SynthesisServiceInterface;
use App\Domain\Synthesis\SynthesisUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service Application — Orchestration de la synthèse IA d'un article (US-010 + US-011 + US-012).
 *
 * Flux d'exécution :
 * 1. Normalisation/canonicalisation de l'URL via UrlNormalizer (US-012 T-012-01)
 *    Lowercase scheme+host, tri query params, suppression fragment, rejet \r\n\0
 * 2. Validation SSRF stricte de l'URL normalisée (filter_var + rejet IP RFC 1918 + loopback)
 * 3. Cache Redis (clé sha256(normalizedUrl . '_' . level), TTL 24 h) — retour HIT si chaud
 *    En cas d'indisponibilité Redis : status BYPASS, poursuite sans cache
 * 4. Fetch du contenu de l'article (ArticleContentFetcherInterface)
 * 5. Appel Mistral avec prompt adapté au niveau (SynthesisLevel::promptInstructions())
 *    et timeout adapté (SynthesisLevel::timeoutSeconds()) — US-011
 * 6. Parse de la réponse texte Mistral → SynthesisResponse structurée
 * 7. Calcul url_hash SHA-256 sur URL normalisée (jamais l'URL brute dans les logs)
 * 8. Persistence du résultat avec le niveau (SynthesisResultRepositoryInterface)
 * 9. Mise en cache de la réponse (sauf si BYPASS) (SynthesisCacheInterface)
 *
 * Sécurité :
 * - SSRF : validation URL + résolution DNS + rejet RFC 1918/loopback (OWASP A01)
 * - Anti key-injection : UrlNormalizer rejette \r, \n, \0 avant tout traitement
 * - PII : aucun email / UUID utilisateur dans le prompt Mistral (RGPD — T-010-11)
 * - Logging : url_hash et level uniquement, jamais l'URL brute ni l'identifiant utilisateur
 *
 * Deptrac : Application → Domain uniquement.
 *
 * @see SynthesisServiceInterface
 */
final class SynthesisService implements SynthesisServiceInterface
{
    /** TTL cache Redis : 24 h — une entrée par URL+niveau (US-011 T-011-05). */
    private const CACHE_TTL = 86400;

    public function __construct(
        private readonly MistralClientInterface $mistralClient,
        private readonly ArticleContentFetcherInterface $contentFetcher,
        private readonly SynthesisResultRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly ?SynthesisCacheInterface $cache = null,
        private readonly ?UrlNormalizer $normalizer = null,
    ) {
    }

    /**
     * Génère une synthèse IA pour l'URL fournie au niveau demandé.
     *
     * Retourne un SynthesisResponseWithCacheStatus avec :
     *   - HIT    : synthèse servie depuis Redis (aucun appel Mistral)
     *   - MISS   : synthèse générée par Mistral et mise en cache
     *   - BYPASS : Redis indisponible, synthèse générée sans cache
     *
     * @throws InvalidSynthesisUrlException si l'URL est malformée, contient des caractères
     *                                      de contrôle (\r\n\0), ou cible une IP privée (SSRF)
     * @throws SynthesisUnavailableException si Mistral est inaccessible
     */
    public function synthesize(SynthesisRequest $request): SynthesisResponseWithCacheStatus
    {
        // ── 1. Normalisation URL + validation SSRF ───────────────────────────
        // L'URL normalisée est utilisée pour le cache key et le url_hash loggué.
        // La validation SSRF opère sur l'URL normalisée (lowercase host, etc.).
        $normalizedUrl = null !== $this->normalizer
            ? $this->normalizer->normalize($request->url)
            : $request->url;

        $this->validateUrlForSsrf($normalizedUrl);

        // ── 2. Cache check ───────────────────────────────────────────────────
        $cacheKey = $this->buildCacheKey($normalizedUrl, $request->level);
        $cacheStatus = SynthesisResponseWithCacheStatus::MISS;

        if (null !== $this->cache) {
            try {
                $cached = $this->cache->get($cacheKey);

                if (null !== $cached) {
                    $this->logger->debug('synthesis.cache_hit', [
                        'url_hash' => hash('sha256', $normalizedUrl),
                        'level' => $request->level->value,
                    ]);

                    return new SynthesisResponseWithCacheStatus($cached, SynthesisResponseWithCacheStatus::HIT);
                }

                // Cache disponible mais pas d'entrée → MISS (comportement par défaut)
                $this->logger->debug('synthesis.cache_miss', [
                    'url_hash' => hash('sha256', $normalizedUrl),
                    'level' => $request->level->value,
                ]);
            } catch (\Throwable) {
                // Redis indisponible → BYPASS : appel Mistral direct, pas de mise en cache
                $cacheStatus = SynthesisResponseWithCacheStatus::BYPASS;
            }
        }

        // ── 3. Fetch du contenu ──────────────────────────────────────────────
        // Fetch avec l'URL originale (pour respecter les redirections du serveur)
        $fetched = $this->contentFetcher->fetchContent($request->url);

        // ── 4. Appel Mistral (prompt + timeout adaptés au niveau) ────────────
        // PII-safe : le contenu de l'article ne contient jamais d'UUID utilisateur
        $rawResponse = $this->mistralClient->complete(
            $request->level->promptInstructions(),
            $fetched->text,
            $request->level->timeoutSeconds(),
        );

        // ── 5. Parse de la réponse ───────────────────────────────────────────
        $response = $this->parseResponse($rawResponse, $request->url, $fetched->isPartial);

        // ── 6. Persistence ───────────────────────────────────────────────────
        // url_hash calculé sur l'URL normalisée (PII-safe, déterministe, clé unique)
        $urlHash = hash('sha256', $normalizedUrl);
        $createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $result = new SynthesisResult(
            id: Uuid::v4()->toRfc4122(),
            urlHash: $urlHash,
            level: $request->level->value,
            content: $response->content,
            keyPoints: $response->keyPoints,
            sources: $response->sources,
            createdAt: $createdAt,
        );

        $this->repository->save($result);

        // ── 7. Cache put (seulement si Redis est disponible — pas de BYPASS) ─
        if (SynthesisResponseWithCacheStatus::BYPASS !== $cacheStatus && null !== $this->cache) {
            $this->cache->set($cacheKey, $response, self::CACHE_TTL);
        }

        // ── 8. Logging (url_hash + level uniquement, jamais l'URL brute) ────
        $this->logger->info('synthesis.generated', [
            'url_hash' => $urlHash,
            'level' => $request->level->value,
            'is_partial' => $fetched->isPartial,
            'cache_status' => $cacheStatus,
        ]);

        return new SynthesisResponseWithCacheStatus($response, $cacheStatus);
    }

    // ── Cache key ─────────────────────────────────────────────────────────────

    /**
     * Construit la clé de cache pour une URL normalisée + niveau donnés.
     *
     * Format : sha256(normalizedUrl . '_' . level.value)
     * 3 clés distinctes par URL normalisée (une par niveau) — US-011 T-011-05.
     * Canonicalisation (US-012) : l'URL est normalisée avant hash pour maximiser les hits.
     * PII-safe : sha256 opaque, jamais l'URL en clair dans la clé Redis.
     *
     * @param string $normalizedUrl URL normalisée par UrlNormalizer
     * @param SynthesisLevel $level Niveau de synthèse (concise|detailed|narrative)
     */
    private function buildCacheKey(string $normalizedUrl, SynthesisLevel $level): string
    {
        return hash('sha256', $normalizedUrl . '_' . $level->value);
    }

    // ── SSRF Validation ───────────────────────────────────────────────────────

    /**
     * Valide l'URL contre les attaques SSRF (OWASP A01).
     *
     * Étapes :
     * 1. Format URL via filter_var FILTER_VALIDATE_URL
     * 2. Schéma http ou https uniquement
     * 3. Résolution DNS du hostname
     * 4. Rejet des IP RFC 1918 + loopback + adresses réservées
     *
     * @throws InvalidSynthesisUrlException si l'URL est invalide ou SSRF détecté
     */
    private function validateUrlForSsrf(string $url): void
    {
        // Étape 1 — Format
        if (false === filter_var($url, \FILTER_VALIDATE_URL)) {
            throw new InvalidSynthesisUrlException('URL invalide — vérifiez le format de l\'adresse');
        }

        // Étape 2 — Schéma http/https uniquement
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');

        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidSynthesisUrlException('URL invalide — vérifiez le format de l\'adresse');
        }

        $host = $parsed['host'] ?? '';

        // Étape 3 — Rejet des hostnames loopback explicites
        if ('localhost' === $host || '127.0.0.1' === $host || '::1' === $host) {
            throw new InvalidSynthesisUrlException('URL invalide — vérifiez le format de l\'adresse');
        }

        // Étape 4 — Vérification si le host est déjà une IP
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return;
        }

        // Étape 5 — Résolution DNS + vérification IP résolue
        $resolvedIp = gethostbyname($host);

        if ($resolvedIp !== $host) {
            // Résolution réussie → vérifier que ce n'est pas une IP privée
            $this->assertPublicIp($resolvedIp);
        }
        // Si résolution échoue (retourne $host inchangé) → on laisse passer
        // L'appel HTTP suivant échouera et lèvera SynthesisUnavailableException
    }

    /**
     * Vérifie qu'une IP n'est pas dans une plage privée RFC 1918 ou réservée.
     *
     * Utilise FILTER_FLAG_NO_PRIV_RANGE + FILTER_FLAG_NO_RES_RANGE pour exclure :
     * - RFC 1918 : 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
     * - Loopback : 127.0.0.0/8, ::1
     * - Adresses réservées (IANA)
     *
     * @throws InvalidSynthesisUrlException si l'IP est privée ou réservée
     */
    private function assertPublicIp(string $ip): void
    {
        $isPublic = filter_var(
            $ip,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
        );

        if (false === $isPublic) {
            throw new InvalidSynthesisUrlException('URL invalide — vérifiez le format de l\'adresse');
        }
    }

    // ── Parsing réponse Mistral ────────────────────────────────────────────────

    /**
     * Parse la réponse texte de Mistral en SynthesisResponse structuré.
     *
     * Le parsing est permissif : si le format attendu n'est pas respecté,
     * des valeurs par défaut sont utilisées plutôt que lever une exception.
     *
     * @param string $rawResponse Réponse texte brute de Mistral
     * @param string $originalUrl URL de l'article source
     * @param bool $isPartial true si le contenu source était partiel
     */
    private function parseResponse(string $rawResponse, string $originalUrl, bool $isPartial): SynthesisResponse
    {
        $content = $this->extractContent($rawResponse);
        $keyPoints = $this->extractKeyPoints($rawResponse);
        $sources = $this->extractSources($rawResponse);

        // Mention de contenu partiel ajoutée sous le condensé
        if ($isPartial) {
            $content .= "\n\nContenu partiel — accès limité à la source";
        }

        // Garantir le préfixe "BRIEFLY AI:" (fallback si Mistral ne le respecte pas)
        if (!str_starts_with(trim($content), 'BRIEFLY AI:')) {
            $content = 'BRIEFLY AI: ' . trim($content);
        }

        return new SynthesisResponse(
            content: $content,
            keyPoints: [] !== $keyPoints ? $keyPoints : ['01 Point clé non extrait', '02 Point clé non extrait', '03 Point clé non extrait'],
            sources: [] !== $sources ? $sources : ['Source non identifiée'],
            originalUrl: $originalUrl,
            isPartial: $isPartial,
        );
    }

    /**
     * Extrait le contenu principal de la réponse Mistral.
     * Section entre "BRIEFLY AI:" et "KEY POINTS:" (ou fin du texte).
     */
    private function extractContent(string $raw): string
    {
        // Trouver "BRIEFLY AI:" (case-insensitive pour robustesse)
        $brieflyPos = stripos($raw, 'BRIEFLY AI:');

        if (false === $brieflyPos) {
            // Format non respecté — retourner le texte brut
            return trim($raw);
        }

        $content = substr($raw, $brieflyPos);

        // Couper avant "KEY POINTS:" si présent
        $keyPointsPos = stripos($content, 'KEY POINTS:');

        if (false !== $keyPointsPos) {
            $content = substr($content, 0, $keyPointsPos);
        }

        return trim($content);
    }

    /**
     * Extrait les points clés (lignes commençant par 01/02/03/04/05).
     *
     * @return string[]
     */
    private function extractKeyPoints(string $raw): array
    {
        $keyPoints = [];

        // Chercher lignes commençant par 01-05 (avec ou sans espace)
        if (preg_match_all('/^0[12345]\s+.+/m', $raw, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $keyPoints[] = trim($match);
            }
        }

        return $keyPoints;
    }

    /**
     * Extrait les sources citées (section après "SOURCES:").
     *
     * @return string[]
     */
    private function extractSources(string $raw): array
    {
        $sources = [];
        $sourcesPos = stripos($raw, 'SOURCES:');

        if (false === $sourcesPos) {
            return $sources;
        }

        $sourcesSection = substr($raw, $sourcesPos + \strlen('SOURCES:'));
        $lines = explode("\n", trim($sourcesSection));

        foreach ($lines as $line) {
            $line = trim($line);
            if ('' !== $line && !str_starts_with(strtoupper($line), 'KEY POINTS')) {
                $sources[] = $line;
            }
        }

        return $sources;
    }
}
