<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur OAuth — Initiation de la redirection vers le provider (US-031).
 *
 * Deux routes gérées :
 * - GET /oauth/connect/{service}  : initie OAuth2 (génère state, redirige vers provider)
 * - GET /oauth/callback/{service} : callback traité par les Authenticators (GoogleAuthenticator, GithubAuthenticator)
 *
 * La route callback est déclarée ici pour satisfaire le routeur Symfony,
 * mais son traitement est entièrement délégué aux Authenticators via security.yaml.
 *
 * State anti-CSRF : généré et stocké en session par KnpU OAuth2Client
 * lors de client->redirect() — aucun secret en dur ici (constitution §6).
 *
 * Couche Presentation — dépend d'Application et Infrastructure (via interfaces).
 */
final class OAuthController
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
    ) {
    }

    /**
     * Initie la redirection OAuth2 vers Google ou GitHub.
     *
     * Le paramètre `state` cryptographiquement sûr est généré et stocké
     * en session par KnpU OAuth2Client (bin2hex(random_bytes(16)) par défaut).
     *
     * Sécurité : aucun paramètre utilisateur n'est transmis au provider sans validation.
     */
    #[Route('/oauth/connect/{service}', name: 'app_oauth_connect', methods: ['GET'], requirements: ['service' => 'google|github'])]
    public function connect(string $service): RedirectResponse
    {
        return $this->clientRegistry
            ->getClient($service)
            ->redirect([], []);
    }

    /**
     * Route callback OAuth2 — traitée par GoogleAuthenticator ou GithubAuthenticator.
     *
     * Cette méthode ne devrait jamais être appelée directement : les Authenticators
     * interceptent la requête avant qu'elle n'atteigne le contrôleur.
     * Si elle est atteinte, c'est une erreur de configuration.
     */
    #[Route('/oauth/callback/{service}', name: 'app_oauth_callback', methods: ['GET'], requirements: ['service' => 'google|github'])]
    public function callback(Request $request): Response
    {
        // Ce code ne devrait jamais être atteint (les Authenticators interceptent en amont)
        return new Response(
            'Erreur de configuration OAuth. Veuillez contacter le support.',
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }
}
