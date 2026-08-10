<?php

declare(strict_types=1);

namespace App\Presentation\StateProcessor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Quota\QuotaService;
use App\Application\Quota\UserUuidResolverInterface;
use App\Domain\Feed\ArticleRepositoryInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * State Processor — Synthèse IA réelle d'un article par son ID (US-033 + US-010 + US-013).
 *
 * Route : POST /api/v1/articles/{id}/synthesize
 * Input : ID article depuis le template URI (pas de corps)
 *
 * Résout l'URL de l'article puis délègue au même SynthesisService que
 * POST /api/v1/synthesis (Mistral réel), au niveau CONCISE par défaut.
 *
 * Flux :
 * 1. Authentification (UserUuidResolverInterface)
 * 2. Log WARNING si header X-Date présent (tentative de manipulation de date)
 * 3. Résolution ID article → URL source (404 si inconnu)
 * 4. Bypass Premium OU Quota check / consommation (QuotaService) — avant tout appel Mistral
 * 5. Synthèse (SynthesisServiceInterface : SSRF + fetch + Mistral + cache + persistence)
 * 6. Remboursement quota si erreur serveur IA post-débit (refund — US-013 T-013-06)
 * 7. Réponse SynthesisResource complète + header X-Quota-Remaining (Free uniquement)
 *
 * Réponses : 200 | 401 | 402 | 404 | 422 | 503 (cf. UrlSynthesisProcessor).
 *
 * Couche Presentation (deptrac : Presentation → Domain, Application).
 *
 * @implements ProcessorInterface<mixed, SynthesisResource>
 */
final class ArticleSynthesisProcessor implements ProcessorInterface
{
    /** Clé de l'attribut de requête pour X-Quota-Remaining (lu par QuotaSubscriber). */
    public const QUOTA_REMAINING_ATTRIBUTE = 'quota_remaining';

    public function __construct(
        private readonly SynthesisServiceInterface $synthesisService,
        private readonly ArticleRepositoryInterface $articleRepository,
        private readonly QuotaService $quotaService,
        private readonly UserUuidResolverInterface $userUuidResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @throws AccessDeniedException si l'utilisateur n'est pas authentifié
     * @throws NotFoundHttpException si l'article n'existe pas (HTTP 404)
     * @throws UnprocessableEntityHttpException si l'URL de l'article est invalide (HTTP 422)
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

        // ── 3. Résoudre l'article → URL source ──────────────────────────────
        $rawId = $uriVariables['id'] ?? '';
        $articleId = \is_string($rawId) ? $rawId : '';
        $url = '' !== $articleId ? $this->articleRepository->findUrlById($articleId) : null;

        if (null === $url) {
            throw new NotFoundHttpException('Article introuvable.');
        }

        // ── 4. Quota check (US-013) — Premium bypass + Free limit ──────────
        $isPremium = $this->quotaService->isPremium($userUuid);

        if (!$isPremium) {
            try {
                $allowed = $this->quotaService->consumeOrDeny($userUuid);
            } catch (QuotaServiceUnavailableException $e) {
                $this->logger->warning('synthesis.quota_redis_ko', [
                    'context' => 'ArticleSynthesisProcessor::process',
                ]);

                throw new ServiceUnavailableHttpException(retryAfter: null, message: 'Le service est temporairement indisponible. Veuillez réessayer dans quelques instants.', previous: $e);
            }

            if (!$allowed) {
                // HTTP 402 Payment Required (US-013 — remplace 429 US-033)
                throw new HttpException(Response::HTTP_PAYMENT_REQUIRED, 'quota_exceeded', headers: ['X-Quota-Remaining' => '0', 'X-Resets-At' => $this->quotaService->nextMidnightUtcIso()]);
            }
        }

        // ── 5. Synthèse IA (niveau CONCISE par défaut) ──────────────────────
        try {
            $synthesisResult = $this->synthesisService->synthesize(new SynthesisRequest($url, SynthesisLevel::CONCISE));
        } catch (InvalidSynthesisUrlException $e) {
            throw new UnprocessableEntityHttpException('URL invalide — vérifiez le format de l\'adresse', $e);
        } catch (SynthesisUnavailableException $e) {
            $this->logger->error('synthesis.mistral_unavailable', [
                'url_hash' => hash('sha256', $url),
                'level' => SynthesisLevel::CONCISE->value,
                'error' => $e->getMessage(),
            ]);

            // Remboursement quota si l'utilisateur Free a été pré-débité (US-013 T-013-06)
            if (!$isPremium) {
                $this->quotaService->refund($userUuid);
            }

            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'Service temporairement indisponible — réessayez dans quelques instants.', previous: $e);
        }

        $response = $synthesisResult->response;

        // ── 6. Header X-Cache (HIT|MISS|BYPASS) via attribut de requête ─────
        if ($currentRequest instanceof Request) {
            $currentRequest->attributes->set(
                SynthesisCacheHeaderSubscriber::REQUEST_ATTRIBUTE,
                $synthesisResult->cacheStatus,
            );
        }

        // ── 7. Quota courant post-consommation (Free uniquement) ─────────────
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

        // ── 8. Réponse complète ─────────────────────────────────────────────
        return new SynthesisResource(
            id: Uuid::v4()->toRfc4122(),
            articleId: $articleId,
            level: SynthesisLevel::CONCISE->value,
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
