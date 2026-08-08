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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * State Processor — Synthèse IA réelle d'un article par son ID (US-033 + US-010).
 *
 * Route : POST /api/v1/articles/{id}/synthesize
 * Input : ID article depuis le template URI (pas de corps)
 *
 * Résout l'URL de l'article puis délègue au même SynthesisService que
 * POST /api/v1/synthesis (Mistral réel), au niveau CONCISE par défaut.
 *
 * Flux :
 * 1. Authentification (UserUuidResolverInterface)
 * 2. Résolution ID article → URL source (404 si inconnu)
 * 3. Quota check / consommation (QuotaService) — avant tout appel Mistral
 * 4. Synthèse (SynthesisServiceInterface : SSRF + fetch + Mistral + cache + persistence)
 * 5. Réponse SynthesisResource complète (content, keyPoints, sources, ...)
 *
 * Réponses : 200 | 401 | 404 | 422 | 429 | 503 (cf. UrlSynthesisProcessor).
 *
 * Couche Presentation (deptrac : Presentation → Domain, Application).
 *
 * @implements ProcessorInterface<mixed, SynthesisResource>
 */
final class ArticleSynthesisProcessor implements ProcessorInterface
{
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

        // ── 2. Résoudre l'article → URL source ──────────────────────────────
        $rawId = $uriVariables['id'] ?? '';
        $articleId = \is_string($rawId) ? $rawId : '';
        $url = '' !== $articleId ? $this->articleRepository->findUrlById($articleId) : null;

        if (null === $url) {
            throw new NotFoundHttpException('Article introuvable.');
        }

        // ── 3. Quota check — avant tout appel Mistral ───────────────────────
        try {
            $allowed = $this->quotaService->consumeOrDeny($userUuid);
        } catch (QuotaServiceUnavailableException $e) {
            $this->logger->warning('synthesis.quota_redis_ko', [
                'context' => 'ArticleSynthesisProcessor::process',
            ]);

            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'Le service est temporairement indisponible. Veuillez réessayer dans quelques instants.', previous: $e);
        }

        if (!$allowed) {
            throw new TooManyRequestsHttpException(retryAfter: null, message: 'Vous avez utilisé vos 3 synthèses gratuites aujourd\'hui.', code: 0, headers: ['X-Quota-Remaining' => '0']);
        }

        // ── 4. Synthèse IA (niveau CONCISE par défaut) ──────────────────────
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

            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'Service temporairement indisponible — réessayez dans quelques instants.', previous: $e);
        }

        $response = $synthesisResult->response;

        // ── 4b. Header X-Cache (HIT|MISS|BYPASS) via attribut de requête ────
        /** @var Request|null $currentRequest */
        $currentRequest = $context['request'] ?? null;

        if ($currentRequest instanceof Request) {
            $currentRequest->attributes->set(
                SynthesisCacheHeaderSubscriber::REQUEST_ATTRIBUTE,
                $synthesisResult->cacheStatus,
            );
        }

        // ── 5. Quota courant post-consommation ──────────────────────────────
        try {
            $remaining = $this->quotaService->getRemaining($userUuid);
            $used = $this->quotaService->getUsed($userUuid);
        } catch (QuotaServiceUnavailableException) {
            $remaining = 0;
            $used = QuotaService::DAILY_LIMIT;
        }

        // ── 6. Réponse complète ─────────────────────────────────────────────
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
