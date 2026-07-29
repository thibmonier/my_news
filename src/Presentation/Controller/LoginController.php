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
