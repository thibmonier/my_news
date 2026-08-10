<?php

declare(strict_types=1);

namespace App\Presentation\StateProcessor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Quota\QuotaService;
use App\Application\Quota\UserUuidResolverInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;
use App\Domain\Synthesis\InvalidSynthesisLevelException;
use App\Domain\Synthesis\InvalidSynthesisUrlException;
use App\Domain\Synthesis\SynthesisLevel;
use App\Domain\Synthesis\SynthesisRequest;
use App\Domain\Synthesis\SynthesisServiceInterface;
use App\Domain\Synthesis\SynthesisUnavailableException;
use App\Presentation\ApiResource\SynthesisResource;
use App\Presentation\EventSubscriber\SynthesisCacheHeaderSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * State Processor — Génération de synthèse IA réelle via Mistral (US-010 + US-011 + US-013).
 *
 * Route : POST /api/v1/synthesis
 * Input : corps JSON { "url": "https://...", "level": "concise|detailed|narrative" }
 *
 * Flux :
 * 1. Identifier l'utilisateur (UserUuidResolverInterface)
 * 2. Log WARNING si header X-Date présent (tentative de manipulation de date)
 * 3. Bypass Premium OU Quota check/consommation (QuotaService — US-013)
 * 4. Extraire l'URL + le niveau depuis le corps de la requête
 * 5. Déléguer à SynthesisServiceInterface (validation SSRF + fetch + Mistral + persistence)
 * 6. Remboursement quota si erreur serveur IA post-débit (refund — US-013)
 * 7. Retourner SynthesisResource avec X-Quota-Remaining header (Free uniquement)
 *
 * Réponses :
 *   200  synthèse "BRIEFLY AI:" avec level, keyPoints, sources, originalUrl, isPartial
 *        + header X-Quota-Remaining: N (comptes Free seulement)
 *   401  utilisateur non authentifié (AccessDeniedException → Symfony Security)
 *   402  quota quotidien épuisé (HttpException 402 + JSON quota_exceeded + resets_at)
 *   422  URL invalide / SSRF détecté / level inconnu (message spécifique)
 *   503  Mistral ou Redis inaccessibles (ServiceUnavailableHttpException, sans stacktrace)
 *
 * Sécurité :
 * - Quota consommé AVANT appel Mistral (pas de bypass possible)
 * - Date quota = UTC serveur — header X-Date client ignoré + loggué en WARNING
 * - url_hash loggué en cas d'erreur (jamais l'URL brute ni l'UUID utilisateur — RGPD)
 * - Réponse 503 générique sans détail technique (OWASP A05)
 * - Validation level : whitelist enum stricte via SynthesisLevel::fromString() (T-011-06)
 *
 * Couche Presentation (deptrac : Presentation → Domain, Application).
 *
 * @implements ProcessorInterface<mixed, SynthesisResource>
 */
final class UrlSynthesisProcessor implements ProcessorInterface
{
    /** Clé de l'attribut de requête pour X-Quota-Remaining (lu par QuotaSubscriber). */
    public const QUOTA_REMAINING_ATTRIBUTE = 'quota_remaining';

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
     * @throws UnprocessableEntityHttpException si URL invalide, SSRF ou level invalide (HTTP 422)
     * @throws HttpException si quota épuisé (HTTP 402 Payment Required)
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

        /** @var Request|null $currentRequest */
        $currentRequest = $context['request'] ?? null;

        // ── 2. Sécurité date : log WARNING si X-Date présent (manipulation tentée) ─
        if ($currentRequest instanceof Request && $currentRequest->headers->has('X-Date')) {
            $this->logger->warning('synthesis.quota_date_manipulation_attempt', [
                'ip' => $currentRequest->getClientIp(),
                'user_agent' => $currentRequest->headers->get('User-Agent'),
                'x_date_value' => $currentRequest->headers->get('X-Date'),
                // RGPD : UUID non loggué
            ]);
        }

        // ── 3. Extraire l'URL et le niveau depuis le corps désérialisé ──────
        $url = $data instanceof SynthesisResource ? trim($data->url) : '';
        $levelRaw = $data instanceof SynthesisResource ? $data->level : null;

        if ('' === $url) {
            throw new UnprocessableEntityHttpException('URL invalide — vérifiez le format de l\'adresse');
        }

        // ── 4. Résoudre le niveau (whitelist strict — US-011 T-011-06) ──────
        try {
            $level = null !== $levelRaw
                ? SynthesisLevel::fromString($levelRaw)
                : SynthesisLevel::CONCISE;
        } catch (InvalidSynthesisLevelException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        // ── 5. Quota check (US-013) — Premium bypass + Free limit ──────────
        $isPremium = $this->quotaService->isPremium($userUuid);

        if (!$isPremium) {
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
                // HTTP 402 Payment Required (US-013 — remplace 429 US-033)
                throw new HttpException(Response::HTTP_PAYMENT_REQUIRED, 'quota_exceeded', headers: ['X-Quota-Remaining' => '0', 'X-Resets-At' => $this->quotaService->nextMidnightUtcIso()]);
            }
        }

        // ── 6. Synthèse IA (normalisation + SSRF + cache + Mistral + persistence) ──
        try {
            $synthesisResult = $this->synthesisService->synthesize(new SynthesisRequest($url, $level));
        } catch (InvalidSynthesisUrlException $e) {
            throw new UnprocessableEntityHttpException('URL invalide — vérifiez le format de l\'adresse', $e);
        } catch (SynthesisUnavailableException $e) {
            // Log avec url_hash (jamais l'URL brute ni l'UUID utilisateur — RGPD)
            $this->logger->error('synthesis.mistral_unavailable', [
                'url_hash' => hash('sha256', $url),
                'level' => $level->value,
                'error' => $e->getMessage(),
            ]);

            // Remboursement quota si l'utilisateur Free a été pré-débité (US-013 T-013-06)
            if (!$isPremium) {
                $this->quotaService->refund($userUuid);
            }

            $message = SynthesisLevel::NARRATIVE === $level
                ? 'Synthèse Narrative indisponible pour ce contenu — essayez le niveau Detailed'
                : 'Service temporairement indisponible — réessayez dans quelques instants.';

            throw new ServiceUnavailableHttpException(retryAfter: null, message: $message, previous: $e);
        }

        $response = $synthesisResult->response;
        $cacheStatus = $synthesisResult->cacheStatus;

        // ── 7. Header X-Cache (HIT|MISS|BYPASS) via attribut de requête ─────
        if ($currentRequest instanceof Request) {
            $currentRequest->attributes->set(
                SynthesisCacheHeaderSubscriber::REQUEST_ATTRIBUTE,
                $cacheStatus,
            );
        }

        // ── 8. Quota courant post-consommation (Free uniquement) ─────────────
        if (!$isPremium) {
            try {
                $remaining = $this->quotaService->getRemaining($userUuid);
                $used = $this->quotaService->getUsed($userUuid);
            } catch (QuotaServiceUnavailableException) {
                $remaining = 0;
                $used = QuotaService::DAILY_LIMIT;
            }

            // Header X-Quota-Remaining ajouté par QuotaSubscriber via cet attribut
            if ($currentRequest instanceof Request) {
                $currentRequest->attributes->set(self::QUOTA_REMAINING_ATTRIBUTE, $remaining);
            }
        } else {
            $remaining = QuotaService::DAILY_LIMIT;
            $used = 0;
        }

        // ── 9. Réponse enrichie avec badge niveau (US-011 T-011-06) ──────────
        return new SynthesisResource(
            id: Uuid::v4()->toRfc4122(),
            url: $url,
            level: $level->value,
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
