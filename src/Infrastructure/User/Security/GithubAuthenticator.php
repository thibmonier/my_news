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
use League\OAuth2\Client\Provider\GithubResourceOwner;
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
 * Authenticator GitHub OAuth2 (US-031).
 *
 * Gère le callback /oauth/callback/github.
 * Gestion spécifique du mode confidentialité GitHub :
 * - Email masqué → utiliser l'email noreply "ID+username@users.noreply.github.com"
 * - Flash info affiché pour inviter à renseigner l'email dans le profil
 *
 * Sécurité :
 * - State CSRF : InvalidStateAuthenticationException si invalide/absent.
 * - Aucun email ni code d'autorisation loggué (OWASP #9 + constitution §6).
 * - Logs incidents : uniquement {ip, user_agent, timestamp, provider}.
 * - Access token GitHub NON persisté en base (RGPD) — il transit uniquement ici.
 *
 * @see OAuthAuthenticateHandler pour la logique métier (création/liaison de compte)
 */
final class GithubAuthenticator extends OAuth2Authenticator
{
    /** Suffixe des emails noreply GitHub (privacy mode). */
    private const GITHUB_NOREPLY_SUFFIX = '@users.noreply.github.com';

    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly OAuthAuthenticateHandler $handler,
        private readonly DoctrineUserRepository $userRepository,
        private readonly RouterInterface $router,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Active cet authenticator uniquement sur la route callback GitHub.
     */
    public function supports(Request $request): bool
    {
        return 'app_oauth_callback' === $request->attributes->get('_route')
            && 'github' === $request->attributes->get('service');
    }

    /**
     * Échange le code d'autorisation contre un access_token, récupère le profil GitHub
     * et construit un Passport Symfony.
     *
     * GitHub privacy mode :
     * - Si GitHub retourne null pour l'email → utiliser l'email noreply formaté
     * - Si GitHub retourne un email noreply → le traiter comme tel
     */
    public function authenticate(Request $request): Passport
    {
        // Cas : l'utilisateur a cliqué "Refuser" sur la page GitHub
        if ($request->query->has('error')) {
            throw new NoAuthCodeAuthenticationException();
        }

        $client = $this->clientRegistry->getClient('github');

        // fetchAccessToken() vérifie le paramètre state (anti-CSRF géré par KnpU)
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($client, $accessToken, $request): ?object {
                /** @var GithubResourceOwner $githubUser */
                $githubUser = $client->fetchUserFromToken($accessToken);

                $idRaw = $githubUser->getId();
                $providerId = null !== $idRaw ? (string) $idRaw : '';
                $email = $githubUser->getEmail();
                $login = $githubUser->getNickname() ?? '';
                $fullName = $githubUser->getName() ?? $login;

                // Gestion email masqué (privacy mode GitHub)
                $isNoreplyEmail = false;
                if (null === $email || '' === $email) {
                    if ('' === $providerId) {
                        throw new CustomUserMessageAuthenticationException("GitHub n'a pas fourni les informations de compte nécessaires.");
                    }
                    // Email noreply généré par GitHub : {id}+{login}@users.noreply.github.com
                    $email = \sprintf('%s+%s%s', $providerId, $login, self::GITHUB_NOREPLY_SUFFIX);
                    $isNoreplyEmail = true;
                } elseif (str_ends_with($email, self::GITHUB_NOREPLY_SUFFIX)) {
                    $isNoreplyEmail = true;
                }

                ['user' => $user, 'isNew' => $isNew] = $this->handler->handle(
                    new OAuthAuthenticateCommand(
                        provider: 'github',
                        providerId: $providerId,
                        email: $email,
                        fullName: $fullName,
                    ),
                );

                // Stocker les messages flash en session
                $session = $request->getSession();
                if ($session instanceof FlashBagAwareSessionInterface) {
                    if ($isNew) {
                        $session->getFlashBag()->add('success', 'Bienvenue sur Briefly AI !');
                    }
                    if ($isNoreplyEmail) {
                        $session->getFlashBag()->add(
                            'info',
                            'Pour recevoir les notifications, renseignez votre email dans votre profil.',
                        );
                    }
                }

                return $this->userRepository->findEntityByEmail($email);
            }),
        );
    }

    /**
     * Succès : redirection vers /dashboard.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    /**
     * Échec : redirection vers /login avec message flash approprié.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof InvalidStateAuthenticationException) {
            $this->logger->warning('OAuth state invalide détecté (potentielle tentative CSRF)', [
                'ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent'),
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'provider' => 'github',
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
