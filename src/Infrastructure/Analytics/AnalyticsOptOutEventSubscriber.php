<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Application\Privacy\PrivacySettingsService;
use App\Domain\User\IdentifiableUserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Subscriber Infrastructure — Headers opt-out analytics et robots (US-035 T-035-08).
 *
 * Sur chaque réponse principale d'un utilisateur authentifié :
 *   - `analytics_opt_in = false` → injecte `X-Analytics-Opt-Out: 1`
 *     (le template base.html.twig exclura le script Plausible/Matomo)
 *   - `search_engine_indexing = false` → injecte `X-Robots-Tag: noindex, nofollow`
 *     (pris en compte par Googlebot et autres crawlers sur toutes les pages)
 *
 * Conformité RGPD :
 *   - 0 PII dans les logs (aucun UUID, aucun email — RGPD §5.1.f)
 *   - Les headers s'appliquent côté serveur (pas manipulables par le client)
 *
 * Couche Infrastructure — dépend de Application + Domain (deptrac conforme).
 */
final class AnalyticsOptOutEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PrivacySettingsService $privacySettingsService,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (null === $token) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof IdentifiableUserInterface) {
            return;
        }

        try {
            $settings = $this->privacySettingsService->getSettings($user->getUserUuid());
        } catch (\Throwable) {
            // Fail-open : si la DB est indisponible, ne pas bloquer la réponse
            // 0 PII dans les logs (RGPD)
            $this->logger->warning('privacy.settings_unavailable', [
                'event' => 'privacy_settings_lookup_failed',
            ]);

            return;
        }

        $response = $event->getResponse();

        if (!$settings->analyticsOptIn) {
            // X-Analytics-Opt-Out: 1 → le template Twig exclut Plausible/Matomo
            $response->headers->set('X-Analytics-Opt-Out', '1');
        }

        if (!$settings->searchEngineIndexing) {
            // X-Robots-Tag côté serveur → pris en compte par Googlebot (RFC côté crawlers)
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}
