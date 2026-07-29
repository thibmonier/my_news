<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Contrôleur — Page de connexion (stub Walking Skeleton).
 *
 * Route : GET /login
 *         POST /login (géré par Symfony Security form_login — pas de méthode ici)
 *
 * Sprint 1 : HTML inline (Twig non installé).
 * Le traitement POST est délégué à Symfony Security (form_login dans security.yaml).
 *
 * Note : Les erreurs d'authentification sont fournies par AuthenticationUtils
 * (pas d'exposition de détails — OWASP #8).
 *
 * Couche Presentation — dépend uniquement de l'infrastructure Symfony HTTP.
 */
final class LoginController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function __invoke(AuthenticationUtils $authenticationUtils): Response
    {
        $lastEmail = htmlspecialchars(
            $authenticationUtils->getLastUsername(),
            \ENT_QUOTES | \ENT_HTML5,
        );

        $errorHtml = '';
        $error = $authenticationUtils->getLastAuthenticationError();

        if (null !== $error) {
            $errorHtml = '<div class="error" role="alert">Email ou mot de passe incorrect.</div>';
        }

        return new Response(<<<HTML
            <!DOCTYPE html>
            <html lang="fr">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Connexion — Briefly AI</title>
                <style>
                    body { font-family: system-ui, sans-serif; max-width: 480px; margin: 2rem auto; padding: 1rem; }
                    label { display: block; margin-top: 1rem; font-weight: bold; }
                    input[type="email"], input[type="password"] {
                        width: 100%; padding: .5rem; margin-top: .25rem; box-sizing: border-box;
                        border: 1px solid #ccc; border-radius: 4px;
                    }
                    .error { color: #dc2626; background: #fee2e2; padding: .75rem; border-radius: 4px; margin-bottom: 1rem; }
                    button[type="submit"] {
                        margin-top: 1.5rem; width: 100%; padding: .75rem;
                        background: #10B981; color: white; border: none; border-radius: 4px;
                        font-size: 1rem; cursor: pointer;
                    }
                    button[type="submit"]:hover { background: #059669; }
                    .links { text-align: center; margin-top: 1rem; }
                    .oauth-divider { display: flex; align-items: center; margin: 1.5rem 0 1rem; }
                    .oauth-divider::before, .oauth-divider::after { content: ''; flex: 1; border-bottom: 1px solid #e5e7eb; }
                    .oauth-divider span { padding: 0 0.75rem; color: #6b7280; font-size: .875rem; }
                    .oauth-buttons { display: flex; flex-direction: column; gap: .75rem; }
                    .oauth-btn { display: flex; align-items: center; justify-content: center; gap: .5rem;
                        padding: .625rem 1rem; border: 1px solid #d1d5db; border-radius: 4px;
                        background: #fff; color: #374151; text-decoration: none; font-size: .95rem;
                        transition: background .15s; }
                    .oauth-btn:hover { background: #f9fafb; }
                </style>
            </head>
            <body>
                <h1>Se connecter</h1>
                {$errorHtml}
                <form method="POST" action="/login">
                    <label for="email">Adresse email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="{$lastEmail}"
                        required
                        autocomplete="email"
                    >

                    <label for="password">Mot de passe</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        autocomplete="current-password"
                    >

                    <button type="submit">Se connecter</button>
                </form>

                <div class="oauth-divider">
                    <span>ou continuer avec</span>
                </div>

                <div class="oauth-buttons">
                    <a href="/oauth/connect/google" class="oauth-btn oauth-google">
                        <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                        </svg>
                        Continuer avec Google
                    </a>
                    <a href="/oauth/connect/github" class="oauth-btn oauth-github">
                        <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path fill="currentColor" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/>
                        </svg>
                        Continuer avec GitHub
                    </a>
                </div>

                <div class="links">
                    <p>Pas encore de compte ? <a href="/register">S'inscrire</a></p>
                </div>
            </body>
            </html>
            HTML,
            Response::HTTP_OK,
        );
    }
}
