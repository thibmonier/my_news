<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Quota\QuotaService;
use App\Application\Quota\UserUuidResolverInterface;
use App\Domain\Quota\QuotaServiceUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur — Quota de synthèses IA (US-033).
 *
 * Route : GET /api/v1/quota
 * Auth  : ROLE_USER requis
 *
 * Réponses :
 *   200 {"used": N, "limit": 3, "remaining": R}
 *   503 {"error": "service_unavailable", "message": "..."} si Redis KO
 *   401 si non authentifié (géré par le firewall Symfony)
 *
 * Utilisé par le Turbo Frame `quota-indicator` dans le layout header
 * pour afficher "N / 3 synthèses utilisées" en temps réel.
 *
 * RGPD : aucune donnée personnelle dans la réponse ni dans les logs.
 *
 * Couche Presentation (deptrac : Presentation → Domain, Application).
 */
#[Route('/api/v1/quota', name: 'api_v1_quota', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class QuotaController
{
    public function __construct(
        private readonly QuotaService $quotaService,
        private readonly UserUuidResolverInterface $userUuidResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $userUuid = $this->userUuidResolver->getCurrentUserUuid();

        if (null === $userUuid) {
            throw new AccessDeniedException('L\'utilisateur n\'est pas authentifié.');
        }

        try {
            $used = $this->quotaService->getUsed($userUuid);
            $remaining = $this->quotaService->getRemaining($userUuid);
        } catch (QuotaServiceUnavailableException $e) {
            $this->logger->warning(
                $e->getMessage(),
                ['context' => 'QuotaController::__invoke'],
            );

            return new JsonResponse(
                [
                    'error' => 'service_unavailable',
                    'message' => 'Le service est temporairement indisponible. Veuillez réessayer dans quelques instants.',
                ],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse([
            'used' => $used,
            'limit' => QuotaService::DAILY_LIMIT,
            'remaining' => $remaining,
        ]);
    }
}
