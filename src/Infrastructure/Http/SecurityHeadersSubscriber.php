<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscriber HTTP — Headers de sécurité sur toutes les réponses (US-001/T-001-07).
 *
 * Applique les headers de sécurité 2026 (constitution §6 + ADR règle 11-security.md) :
 * - Content-Security-Policy (CSP Level 3)
 * - Strict-Transport-Security (HSTS)
 * - X-Frame-Options : DENY (anti-clickjacking)
 * - X-Content-Type-Options : nosniff
 * - Referrer-Policy
 * - Cross-Origin-Opener-Policy (COOP) — Spectre isolation
 * - Cross-Origin-Embedder-Policy (COEP)
 * - Cross-Origin-Resource-Policy (CORP)
 * - Permissions-Policy
 *
 * Placé dans Infrastructure (couche HTTP) — dépend uniquement de Symfony HttpKernel.
 * Deptrac : Infrastructure:[Domain, Application].
 *
 * SÉCURITÉ OWASP #5 (Security Misconfiguration) :
 * - Headers appliqués sur toutes les réponses (pas seulement les succès)
 * - Master response uniquement (évite la double application sur les sub-requests)
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    /**
     * CSP : politique restrictive sans 'unsafe-inline' ni 'unsafe-eval'.
     * Sprint 1 : pas de CDN externe, pas de polices Google (design tokens CSS inline).
     * À étendre en Sprint 2 si ressources externes ajoutées (fonts, analytics).
     */
    private const CSP = "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; "   // unsafe-inline requis pour design-tokens CSS inline Sprint 1
        . "img-src 'self' data:; "
        . "font-src 'self'; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none'; "
        . "form-action 'self'; "
        . "base-uri 'self'; "
        . 'upgrade-insecure-requests';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    /**
     * Injecte les headers de sécurité sur la réponse principale.
     *
     * Ne traite que les "master requests" pour éviter la double injection
     * sur les sub-requests Symfony (ESI, forward, etc.).
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

        // CSP Level 3 — Injection XSS principale défense (OWASP #3)
        $headers->set('Content-Security-Policy', self::CSP);

        // HSTS — HTTPS forcé (TLS 1.3, max-age 1 an, sous-domaines inclus)
        // Sprint 1 : preload retiré (pas encore soumis à la HSTS preload list)
        $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        // Anti-clickjacking (OWASP #1 Broken Access Control)
        $headers->set('X-Frame-Options', 'DENY');

        // Prévention MIME sniffing (OWASP #5)
        $headers->set('X-Content-Type-Options', 'nosniff');

        // Referrer : envoie uniquement l'origine sur cross-origin, chemin complet en same-origin
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Cross-Origin Isolation 2026 — protection Spectre (ADR règle 11 §6)
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Embedder-Policy', 'require-corp');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        // Permissions-Policy — désactiver les APIs inutiles
        $headers->set(
            'Permissions-Policy',
            'geolocation=(), camera=(), microphone=(), payment=(), usb=(), interest-cohort=()',
        );
    }
}
