<?php

declare(strict_types=1);

namespace App\Presentation\StateProcessor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Application\Quota\QuotaService;
use App\Application\Quota\UserUuidResolverInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;
use App\Presentation\ApiResource\SynthesisResource;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * State Processor — Décorateur de quota pour la génération de synthèse IA.
 *
 * Vérifie le quota quotidien (3 synthèses/jour/compte free) AVANT de déléguer
 * au processor interne (SynthesisStubProcessor en Sprint 1, Mistral en US-010).
 *
 * Flux :
 * 1. Extraire l'UUID via UserUuidResolverInterface (deptrac : Presentation → Application)
 * 2. QuotaService::consumeOrDeny() → true : déléguer au innerProcessor
 * 3. QuotaService::consumeOrDeny() → false : TooManyRequestsHttpException (HTTP 429)
 *    + header X-Quota-Remaining: 0
 * 4. QuotaServiceUnavailableException : ServiceUnavailableHttpException (HTTP 503)
 *    Fail-safe : Redis KO → 503, aucune synthèse générée, aucun bypass
 *
 * Sécurité :
 * - Compteur lié à user.uuid (UUID non contournable par VPN)
 * - Logging WARN sans données personnelles (RGPD)
 *
 * Couche Presentation (deptrac : Presentation → Domain, Application).
 *
 * @implements ProcessorInterface<mixed, SynthesisResource>
 */
final class QuotaStateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, SynthesisResource> $innerProcessor
     */
    public function __construct(
        private readonly ProcessorInterface $innerProcessor,
        private readonly QuotaService $quotaService,
        private readonly UserUuidResolverInterface $userUuidResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Vérifie le quota puis délègue au processor interne si autorisé.
     *
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @throws AccessDeniedException si l'utilisateur n'est pas authentifié
     * @throws TooManyRequestsHttpException si le quota quotidien est épuisé (HTTP 429)
     * @throws ServiceUnavailableHttpException si Redis est inaccessible (HTTP 503)
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): SynthesisResource {
        // ── 1. Identifier l'utilisateur (via UserUuidResolverInterface) ────────
        $userUuid = $this->userUuidResolver->getCurrentUserUuid();

        if (null === $userUuid) {
            throw new AccessDeniedException('L\'utilisateur n\'est pas authentifié.');
        }

        // ── 2. Vérifier le quota (Redis) ───────────────────────────────────────
        try {
            $allowed = $this->quotaService->consumeOrDeny($userUuid);
        } catch (QuotaServiceUnavailableException $e) {
            // Fail-safe : Redis KO → HTTP 503, aucune synthèse générée
            $this->logger->warning(
                $e->getMessage(),
                ['context' => 'QuotaStateProcessor::process'],
                // RGPD : UUID non logué en WARNING
            );

            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'Le service est temporairement indisponible. Veuillez réessayer dans quelques instants.', previous: $e);
        }

        if (!$allowed) {
            // Quota épuisé → HTTP 429 + header X-Quota-Remaining: 0
            throw new TooManyRequestsHttpException(retryAfter: null, message: 'Vous avez utilisé vos 3 synthèses gratuites aujourd\'hui.', previous: null, code: 0, headers: ['X-Quota-Remaining' => '0']);
        }

        // ── 3. Déléguer au processor interne (SynthesisStubProcessor Sprint 1) ─
        $result = $this->innerProcessor->process($data, $operation, $uriVariables, $context);

        // ── 4. Enrichir la réponse avec le quota courant ──────────────────────
        try {
            $remaining = $this->quotaService->getRemaining($userUuid);
            $used = $this->quotaService->getUsed($userUuid);
        } catch (QuotaServiceUnavailableException) {
            // Redis KO après consommation — réponse sans quota (synthèse déjà générée)
            $remaining = 0;
            $used = QuotaService::DAILY_LIMIT;
        }

        return new SynthesisResource(
            id: $result->id,
            articleId: $result->articleId,
            content: $result->content,
            generatedAt: $result->generatedAt,
            quotaUsed: $used,
            quotaRemaining: $remaining,
        );
    }
}
