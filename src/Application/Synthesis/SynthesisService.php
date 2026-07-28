<?php

declare(strict_types=1);

namespace App\Application\Synthesis;

use App\Domain\Synthesis\ArticleContentFetcherInterface;
use App\Domain\Synthesis\InvalidSynthesisUrlException;
use App\Domain\Synthesis\MistralClientInterface;
use App\Domain\Synthesis\SynthesisRequest;
use App\Domain\Synthesis\SynthesisResponse;
use App\Domain\Synthesis\SynthesisResult;
use App\Domain\Synthesis\SynthesisResultRepositoryInterface;
use App\Domain\Synthesis\SynthesisServiceInterface;
use App\Domain\Synthesis\SynthesisUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service Application — Orchestration de la synthèse IA d'un article.
 *
 * Flux d'exécution :
 * 1. Validation SSRF stricte de l'URL (filter_var + rejet IP RFC1918 + loopback)
 * 2. Fetch du contenu de l'article (ArticleContentFetcherInterface)
 * 3. Appel Mistral avec prompt système contrôlé (MistralClientInterface)
 * 4. Parse de la réponse texte Mistral → SynthesisResponse structurée
 * 5. Calcul url_hash SHA-256 (jamais l'URL brute dans les logs)
 * 6. Persistence du résultat (SynthesisResultRepositoryInterface)
 *
 * Sécurité :
 * - SSRF : validation URL + résolution DNS + rejet RFC1918/loopback (OWASP A01)
 * - PII : aucun email / UUID utilisateur dans le prompt Mistral (RGPD — T-010-11)
 * - Logging : url_hash uniquement, jamais l'URL brute ni l'identifiant utilisateur
 *
 * Deptrac : Application → Domain uniquement.
 *
 * @see SynthesisServiceInterface
 */
final class SynthesisService implements SynthesisServiceInterface
{
    /**
     * Prompt système envoyé à Mistral pour la génération de synthèse.
     *
     * Contraintes encodées dans le prompt :
     * - Langue de l'article conservée
     * - Préfixe "BRIEFLY AI:"
     * - 180-220 mots pour le condensé
     * - 3 points clés numérotés 01/02/03
     * - Au moins une source citée
     *
     * PII-safe : aucun UUID utilisateur, email ou IP dans ce prompt.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a professional news analyst for Briefly AI. Your task is to summarize articles concisely.

        IMPORTANT: Respond in the SAME LANGUAGE as the article content. Do NOT translate.

        Format your response EXACTLY as follows (use these exact section headers):

        BRIEFLY AI: [Write a 180-220 word summary of the article here. Be factual and neutral. Start immediately after the colon.]

        KEY POINTS:
        01 [First key takeaway in one sentence]
        02 [Second key takeaway in one sentence]
        03 [Third key takeaway in one sentence]

        SOURCES:
        [Publication name, website domain, or author — at least one]

        Rules:
        - Do NOT include any other text outside this format
        - The summary MUST be 180-220 words
        - Provide EXACTLY 3 key points
        - Cite AT LEAST one source
        - Never mention user names, emails, or personal identifiers
        PROMPT;

    public function __construct(
        private readonly MistralClientInterface $mistralClient,
        private readonly ArticleContentFetcherInterface $contentFetcher,
        private readonly SynthesisResultRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Génère une synthèse IA pour l'URL fournie.
     *
     * @throws InvalidSynthesisUrlException si l'URL est malformée ou cible une IP privée (SSRF)
     * @throws SynthesisUnavailableException si Mistral est inaccessible
     */
    public function synthesize(SynthesisRequest $request): SynthesisResponse
    {
        // ── 1. Validation SSRF ───────────────────────────────────────────────
        $this->validateUrlForSsrf($request->url);

        // ── 2. Fetch du contenu ──────────────────────────────────────────────
        $fetched = $this->contentFetcher->fetchContent($request->url);

        // ── 3. Appel Mistral ─────────────────────────────────────────────────
        // PII-safe : le contenu de l'article ne contient jamais d'UUID utilisateur
        $rawResponse = $this->mistralClient->complete(self::SYSTEM_PROMPT, $fetched->text);

        // ── 4. Parse de la réponse ───────────────────────────────────────────
        $response = $this->parseResponse($rawResponse, $request->url, $fetched->isPartial);

        // ── 5. Persistence ───────────────────────────────────────────────────
        $urlHash = hash('sha256', $request->url);
        $createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $result = new SynthesisResult(
            id: Uuid::v4()->toRfc4122(),
            urlHash: $urlHash,
            level: 'standard',
            content: $response->content,
            keyPoints: $response->keyPoints,
            sources: $response->sources,
            createdAt: $createdAt,
        );

        $this->repository->save($result);

        // ── 6. Logging (url_hash uniquement, jamais l'URL brute) ────────────
        $this->logger->info('synthesis.generated', [
            'url_hash' => $urlHash,
            'is_partial' => $fetched->isPartial,
        ]);

        return $response;
    }

    // ── SSRF Validation ───────────────────────────────────────────────────────

    /**
     * Valide l'URL contre les attaques SSRF (OWASP A01).
     *
     * Étapes :
     * 1. Format URL via filter_var FILTER_VALIDATE_URL
     * 2. Schéma http ou https uniquement
     * 3. Résolution DNS du hostname
     * 4. Rejet des IP RFC1918 + loopback + adresses réservées
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
     * Vérifie qu'une IP n'est pas dans une plage privée RFC1918 ou réservée.
     *
     * Utilise FILTER_FLAG_NO_PRIV_RANGE + FILTER_FLAG_NO_RES_RANGE pour exclure :
     * - RFC1918 : 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
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
     * Extrait les 3 points clés (lignes commençant par 01/02/03).
     *
     * @return string[]
     */
    private function extractKeyPoints(string $raw): array
    {
        $keyPoints = [];

        // Chercher lignes commençant par 01, 02, 03 (avec ou sans espace)
        if (preg_match_all('/^0[123]\s+.+/m', $raw, $matches) > 0) {
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
