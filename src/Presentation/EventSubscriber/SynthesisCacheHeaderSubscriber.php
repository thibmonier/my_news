<?php

declare(strict_types=1);

namespace App\Presentation\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event Subscriber — Injection du header X-Cache dans les réponses de synthèse (US-012).
 *
 * Le statut de cache (HIT|MISS|BYPASS) est stocké par UrlSynthesisProcessor dans les
 * attributs de la requête courante (`synthesis_x_cache`). Ce subscriber le lit et
 * injecte le header HTTP X-Cache correspondant dans la réponse.
 *
 * Séquence :
 *   1. UrlSynthesisProcessor::process() → $context['request']->attributes->set('synthesis_x_cache', $status)
 *   2. kernel.response → SynthesisCacheHeaderSubscriber::onKernelResponse() → X-Cache: HIT|MISS|BYPASS
 *
 * Header présent uniquement sur les réponses issues de l'endpoint POST /api/v1/synthesis.
 * Non présent sur les erreurs (401, 422, 429, 503) où le processor ne s'exécute pas jusqu'au bout.
 *
 * Valeurs possibles :
 *   - HIT    : synthèse servie depuis Redis, aucun appel Mistral effectué
 *   - MISS   : synthèse générée par Mistral et écrite en cache Redis
 *   - BYPASS : Redis indisponible, synthèse générée directement sans cache
 *
 * Couche Presentation (deptrac : Presentation → Domain, Application).
 */
final class SynthesisCacheHeaderSubscriber implements EventSubscriberInterface
{
    /** Nom de l'attribut de requête utilisé pour transmettre le statut de cache. */
    public const REQUEST_ATTRIBUTE = 'synthesis_x_cache';

    /** Nom du header HTTP de réponse exposé au client. */
    public const HEADER_NAME = 'X-Cache';

    /**
     * Ajoute le header X-Cache à la réponse si le statut de cache a été défini.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $cacheStatus = $event->getRequest()->attributes->get(self::REQUEST_ATTRIBUTE);

        if (!\is_string($cacheStatus)) {
            return;
        }

        $event->getResponse()->headers->set(self::HEADER_NAME, $cacheStatus);
    }

    /**
     * @return array<string, list<array{0: string, 1?: int}|int|string>|string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }
}
