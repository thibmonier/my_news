<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Health\GetHealthHandler;
use App\Application\Health\GetHealthQuery;
use App\Domain\Health\ComponentStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint de santé — GET /api/health.
 *
 * Traverse la stack complète HTTP → Application → Domain → Infrastructure.
 * Prouve que PostgreSQL + Redis sont joignables (Walking Skeleton Sprint 0).
 *
 * Répond :
 *   - 200 OK     si tous les composants sont sains
 *   - 503 Service Unavailable si au moins un composant est dégradé
 *
 * Aucune auth requise (endpoint diagnostic non exposé en prod via HTTPS uniquement).
 *
 * Couche Presentation — dépend de Application + Domain uniquement
 * (deptrac : Presentation:[Domain, Application]).
 */
final class HealthController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(GetHealthHandler $handler): JsonResponse
    {
        $report = $handler->handle(new GetHealthQuery());

        $httpStatus = $report->isHealthy()
            ? Response::HTTP_OK
            : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse(
            data: [
                'status' => $report->getStatus(),
                'components' => array_map(
                    static fn (ComponentStatus $c): array => [
                        'name' => $c->name,
                        'status' => $c->status,
                        'message' => $c->message,
                    ],
                    $report->getComponents(),
                ),
                'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->format(\DateTimeInterface::ATOM),
            ],
            status: $httpStatus,
        );
    }
}
