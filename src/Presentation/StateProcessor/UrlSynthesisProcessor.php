<?php

declare(strict_types=1);

namespace App\Presentation\StateProcessor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Quota\QuotaService;
use App\Application\Quota\UserUuidResolverInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;
use App\Domain\Synthesis\InvalidSynthesisUrlException;
use App\Domain\Synthesis\SynthesisRequest;
use App\Domain\Synthesis\SynthesisServiceInterface;
use App\Domain\Synthesis\SynthesisUnavailableException;
use App\Presentation\ApiResource\SynthesisResource;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * State Processor — Génération de synthèse IA réelle via Mistral (US-010).
 *
 * Route : POST /api/v1/synthesis
 * Input : corps JSON { "url": "https://..." }
 *
 * Flux :
 * 1. Identifier l'utilisateur (UserUuidResolverInterface)
 * 2. Vérifier / consommer le quota (QuotaService — US-033 intégré)
 * 3. Extraire l'URL depuis le corps de la requête
 * 4. Déléguer à SynthesisServiceInterface (validation SSRF + fetch + Mistral + persistence)
 * 5. Retourner SynthesisResource enrichie
 *
 * Réponses :
 *   200  synthèse "BRIEFLY AI:" avec keyPoints, sources, originalUrl, isPartial
 *   401  utilisateur non authentifié (AccessDeniedException → Symfony Security)
 *   422  URL invalide ou SSRF détecté (InvalidSynthesisUrlException)
 *   429  quota quotidien épuisé (TooManyRequestsHttpException + X-Quota-Remaining: 0)
 *   503  Mistral ou Redis inaccessibles (ServiceUnavailableHttpException, sans stacktrace)
 *
 * Sécurité :
 * - Quota consommé AVANT appel Mistral (pas de bypass possible)
 * - url_hash loggué en cas d'erreur (jamais l'URL brute ni l'UUID utilisateur — RGPD)
 * - Réponse 503 générique sans détail technique (OWASP A05)
 *
 * Couche Presentation (deptrac : Presentation → Domain, Application).
 *
 * @implements ProcessorInterface<mixed, SynthesisResource>
 */
final class UrlSynthesisProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SynthesisServiceInterface $synthesisService,
        private readonly QuotaService $quotaService,
        private readonly UserUuidResolverInterface $userUuidResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Traite une requête POST /api/v1/synthesis.
     *
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @throws AccessDeniedException si l'utilisateur n'est pas authentifié
     * @throws UnprocessableEntityHttpException si URL invalide ou SSRF (HTTP 422)
     * @throws TooManyRequestsHttpException si quota épuisé (HTTP 429)
     * @throws ServiceUnavailableHttpException si Mistral ou Redis KO (HTTP 503)
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): SynthesisResource {
        // ── 1. Authentification ─────────────────────────────────────────────
        $userUuid = $this->userUuidResolver->getCurrentUserUuid();

        if (null === $userUuid) {
            throw new AccessDeniedException('L\'utilisateur n\'est pas authentifié.');
        }

        // ── 2. Extraire l'URL depuis le corps désérialisé ───────────────────
        $url = $data instanceof SynthesisResource ? trim($data->url) : '';

        if ('' === $url) {
            throw new UnprocessableEntityHttpException('URL invalide — vérifiez le format de l\'adresse');
        }

        // ── 3. Quota check (US-033) — avant tout appel Mistral ─────────────
        try {
            $allowed = $this->quotaService->consumeOrDeny($userUuid);
        } catch (QuotaServiceUnavailableException $e) {
            $this->logger->warning('synthesis.quota_redis_ko', [
                'context' => 'UrlSynthesisProcessor::process',
                // RGPD : UUID non loggué
            ]);

            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'Le service est temporairement indisponible. Veuillez réessayer dans quelques instants.', previous: $e);
        }

        if (!$allowed) {
            throw new TooManyRequestsHttpException(retryAfter: null, message: 'Vous avez utilisé vos 3 synthèses gratuites aujourd\'hui.', code: 0, headers: ['X-Quota-Remaining' => '0']);
        }

        // ── 4. Synthèse IA (SSRF + fetch + Mistral + persistence) ──────────
        try {
            $response = $this->synthesisService->synthesize(new SynthesisRequest($url));
        } catch (InvalidSynthesisUrlException $e) {
            throw new UnprocessableEntityHttpException('URL invalide — vérifiez le format de l\'adresse', $e);
        } catch (SynthesisUnavailableException $e) {
            // Log avec url_hash (jamais l'URL brute ni l'UUID utilisateur — RGPD)
            $this->logger->error('synthesis.mistral_unavailable', [
                'url_hash' => hash('sha256', $url),
                'error' => $e->getMessage(),
                // OWASP A05 : stack trace non loguée en WARNING/INFO
            ]);

            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'Service temporairement indisponible — réessayez dans quelques instants.', previous: $e);
        }

        // ── 5. Quota courant post-consommation ──────────────────────────────
        try {
            $remaining = $this->quotaService->getRemaining($userUuid);
            $used = $this->quotaService->getUsed($userUuid);
        } catch (QuotaServiceUnavailableException) {
            // Redis KO après consommation — synthèse déjà générée, répondre quand même
            $remaining = 0;
            $used = QuotaService::DAILY_LIMIT;
        }

        // ── 6. Réponse enrichie ─────────────────────────────────────────────
        return new SynthesisResource(
            id: Uuid::v4()->toRfc4122(),
            url: $url,
            content: $response->content,
            keyPoints: $response->keyPoints,
            sources: $response->sources,
            originalUrl: $response->originalUrl,
            isPartial: $response->isPartial,
            generatedAt: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            quotaUsed: $used,
            quotaRemaining: $remaining,
        );
    }
}
