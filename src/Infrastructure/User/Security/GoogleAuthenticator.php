<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Security;

use App\Application\User\OAuthAuthenticate\OAuthAuthenticateCommand;
use App\Application\User\OAuthAuthenticate\OAuthAuthenticateHandler;
use App\Infrastructure\User\Persistence\DoctrineUserRepository;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use KnpU\OAuth2ClientBundle\Security\Exception\InvalidStateAuthenticationException;
use KnpU\OAuth2ClientBundle\Security\Exception\NoAuthCodeAuthenticationException;
use League\OAuth2\Client\Provider\GoogleUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticator Google OAuth2 (US-031).
 *
 * Gère le callback /oauth/callback/google.
 * Le paramètre `state` anti-CSRF est géré automatiquement par KnpU OAuth2Client
 * (stocké en session sous knpu.oauth2_client_state, vérifié lors de fetchAccessToken).
 *
 * Sécurité :
 * - State CSRF : InvalidStateAuthenticationException si invalide/absent.
 * - Aucun email ni code d'autorisation loggué (OWASP #9 + constitution §6).
 * - Logs incidents : uniquement {ip, user_agent, timestamp}.
 * - Access token Google NON persisté en base (RGPD) — il transit uniquement ici.
 *
 * @see OAuthAuthenticateHandler pour la logique métier (création/liaison de compte)
 */
final class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly OAuthAuthenticateHandler $handler,
        private readonly DoctrineUserRepository $userRepository,
        private readonly RouterInterface $router,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Active cet authenticator uniquement sur la route callback Google.
     */
    public function supports(Request $request): bool
    {
        return 'app_oauth_callback' === $request->attributes->get('_route')
            && 'google' === $request->attributes->get('service');
    }

    /**
     * Échange le code d'autorisation contre un access_token, récupère le profil Google
     * et construit un Passport Symfony à partir de l'entité User (via OAuthAuthenticateHandler).
     *
     * Gestion d'erreur :
     * - `error=access_denied` : relève NoAuthCodeAuthenticationException (géré dans onAuthenticationFailure)
     * - State invalide/absent : InvalidStateAuthenticationException (idem)
     */
    public function authenticate(Request $request): Passport
    {
        // Cas : l'utilisateur a cliqué "Refuser" sur la page Google
        if ($request->query->has('error')) {
            throw new NoAuthCodeAuthenticationException();
        }

        $client = $this->clientRegistry->getClient('google');

        // fetchAccessToken() vérifie le paramètre state (anti-CSRF géré par KnpU)
        // Lève InvalidStateAuthenticationException si state invalide/absent
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($client, $accessToken, $request): ?object {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                $email = $googleUser->getEmail();
                // getId() returns mixed (Google API) — safe scalar cast
                $providerIdRaw = $googleUser->getId();
                $providerId = \is_scalar($providerIdRaw) ? (string) $providerIdRaw : '';
                // getName() returns string (never null) — PHPDoc may indicate otherwise
                $name = $googleUser->getName();
                $fullName = '' !== $name ? $name : ($email ?? '');

                if (null === $email || '' === $email) {
                    throw new CustomUserMessageAuthenticationException("Google n'a pas fourni d'adresse email. Veuillez vérifier vos paramètres de confidentialité.");
                }

                ['user' => $user, 'isNew' => $isNew] = $this->handler->handle(
                    new OAuthAuthenticateCommand(
                        provider: 'google',
                        providerId: $providerId,
                        email: $email,
                        fullName: $fullName,
                    ),
                );

                // Stocker isNew en session pour afficher le message de bienvenue
                $session = $request->getSession();
                if ($session instanceof FlashBagAwareSessionInterface) {
                    if ($isNew) {
                        $session->getFlashBag()->add('success', 'Bienvenue sur Briefly AI !');
                    }
                }

                // Retourner l'entité Doctrine (UserInterface) requise par Symfony Security
                return $this->userRepository->findEntityByEmail($email);
            }),
        );
    }

    /**
     * Succès : redirection vers /dashboard (post-login target).
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    /**
     * Échec : redirection vers /login avec message flash approprié.
     *
     * - Accès refusé (NoAuthCode) → message "Connexion annulée"
     * - State invalide (CSRF) → HTTP 400 + log WARN sans PII
     * - Autres erreurs → message générique
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof InvalidStateAuthenticationException) {
            // Log WARN sans données personnelles (OWASP #9 — constitution §6)
            $this->logger->warning('OAuth state invalide détecté (potentielle tentative CSRF)', [
                'ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent'),
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'provider' => 'google',
                // Pas d'email, pas de code d'autorisation (jamais loggués)
            ]);

            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add(
                    'error',
                    'La requête de connexion est invalide. Veuillez recommencer.',
                );
            }

            return new Response(
                content: $this->renderInvalidStateResponse(),
                status: Response::HTTP_BAD_REQUEST,
            );
        }

        if ($exception instanceof NoAuthCodeAuthenticationException) {
            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add(
                    'info',
                    'Connexion annulée. Vous pouvez vous connecter avec votre email.',
                );
            }

            return new RedirectResponse($this->router->generate('app_login'));
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', 'Une erreur est survenue lors de la connexion. Veuillez réessayer.');
        }

        return new RedirectResponse($this->router->generate('app_login'));
    }

    /**
     * Rendu HTML minimaliste pour la réponse HTTP 400 (state invalide).
     * Pas de stack trace, message générique (OWASP #7).
     */
    private function renderInvalidStateResponse(): string
    {
        return <<<'HTML'
            <!DOCTYPE html>
            <html lang="fr">
            <head>
                <meta charset="UTF-8">
                <title>Requête invalide — Briefly AI</title>
                <meta http-equiv="refresh" content="3;url=/login">
                <style>body{font-family:system-ui,sans-serif;max-width:480px;margin:2rem auto;padding:1rem}</style>
            </head>
            <body>
                <h1>Requête invalide</h1>
                <p>La requête de connexion est invalide. Veuillez recommencer.</p>
                <p><a href="/login">Retour à la page de connexion</a></p>
            </body>
            </html>
            HTML;
    }
}
