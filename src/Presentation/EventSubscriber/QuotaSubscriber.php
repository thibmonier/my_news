<?php

declare(strict_types=1);

namespace App\Presentation\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscriber — Gestion HTTP du quota de synthèses IA (US-013).
 *
 * Deux responsabilités :
 *
 * 1. onKernelException (priority 10, avant API Platform) :
 *    Intercepte HttpException avec statusCode 402 + header X-Resets-At
 *    (exception levée par UrlSynthesisProcessor / ArticleSynthesisProcessor)
 *    et formate la réponse JSON :
 *    {"error": "quota_exceeded", "remaining": 0, "resets_at": "<ISO8601 UTC>"}
 *
 * 2. onKernelResponse :
 *    Lit l'attribut de requête `quota_remaining` (positionné par les processors)
 *    et ajoute le header HTTP `X-Quota-Remaining: N` sur les réponses 200
 *    (comptes Free uniquement — Premium n'ont pas cet attribut).
 *
 * Couche Presentation (deptrac : Presentation → rien de spécial).
 */
final class QuotaSubscriber implements EventSubscriberInterface
{
    /** Attribut de requête utilisé par les processors pour X-Quota-Remaining. */
    public const QUOTA_REMAINING_ATTRIBUTE = 'quota_remaining';

    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité 10 : s'exécute AVANT le ExceptionListener d'API Platform
            KernelEvents::EXCEPTION => ['onKernelException', 10],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    /**
     * Formate les exceptions HTTP 402 quota_exceeded en réponse JSON structurée.
     *
     * Identifie les exceptions quota par la présence du header X-Resets-At.
     * Les autres 402 (non quota) ne sont pas interceptées.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $exception = $event->getThrowable();

        if (!$exception instanceof HttpException) {
            return;
        }

        if (Response::HTTP_PAYMENT_REQUIRED !== $exception->getStatusCode()) {
            return;
        }

        $headers = $exception->getHeaders();

        // Signature quota : présence du header X-Resets-At (positionné par les processors)
        if (!isset($headers['X-Resets-At'])) {
            return;
        }

        $resetsAt = $headers['X-Resets-At'];

        $response = new JsonResponse(
            ['error' => 'quota_exceeded', 'remaining' => 0, 'resets_at' => $resetsAt],
            Response::HTTP_PAYMENT_REQUIRED,
        );

        $event->setResponse($response);
    }

    /**
     * Ajoute le header X-Quota-Remaining sur les réponses 200 (Free uniquement).
     *
     * Le processor stocke le quota résiduel via l'attribut `quota_remaining`.
     * Premium : pas d'attribut → pas de header (conforme US-013 scénario 2).
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        /** @var int|null $remaining */
        $remaining = $event->getRequest()->attributes->get(self::QUOTA_REMAINING_ATTRIBUTE);

        if (null === $remaining) {
            return;
        }

        $event->getResponse()->headers->set('X-Quota-Remaining', (string) $remaining);
    }
}
