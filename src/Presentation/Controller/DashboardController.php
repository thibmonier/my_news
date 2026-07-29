<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur — Dashboard utilisateur (stub Walking Skeleton).
 *
 * Route : GET /dashboard
 * Accès : ROLE_USER requis (constitution §6 : deny by default via access_control).
 *
 * Sprint 1 : page minimale affichant le flash de bienvenue post-inscription
 * et un lien vers le brief quotidien.
 *
 * Note Sprint 1 : HTML inline (Twig non installé).
 * À migrer vers templates/dashboard/index.html.twig en Sprint 2.
 *
 * Couche Presentation — dépend uniquement de l'infrastructure Symfony HTTP.
 */
#[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class DashboardController
{
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        $flashMessages = $session instanceof FlashBagAwareSessionInterface
            ? $session->getFlashBag()->get('success')
            : [];
        $flashHtml = '';

        foreach ($flashMessages as $message) {
            if (!\is_string($message) || '' === $message) {
                continue;
            }
            $msg = htmlspecialchars($message, \ENT_QUOTES | \ENT_HTML5);
            $flashHtml .= \sprintf('<div class="flash success" role="status">%s</div>', $msg);
        }

        return new Response(<<<HTML
            <!DOCTYPE html>
            <html lang="fr">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Tableau de bord — Briefly AI</title>
                <style>
                    body { font-family: system-ui, sans-serif; max-width: 800px; margin: 2rem auto; padding: 1rem; }
                    .flash.success {
                        background: #d1fae5; color: #065f46; border: 1px solid #10B981;
                        padding: .75rem 1rem; border-radius: 4px; margin-bottom: 1rem;
                    }
                    a.btn {
                        display: inline-block; padding: .75rem 1.5rem;
                        background: #10B981; color: white; text-decoration: none;
                        border-radius: 4px; margin-top: 1rem;
                    }
                    a.btn:hover { background: #059669; }
                    nav { display: flex; gap: 1rem; margin-bottom: 2rem; }
                    nav a { color: #6b7280; text-decoration: none; }
                    nav a:hover { color: #111827; }
                </style>
            </head>
            <body>
                <nav>
                    <a href="/dashboard">Tableau de bord</a>
                    <a href="/brief">Mon brief</a>
                    <a href="/logout">Se déconnecter</a>
                </nav>

                {$flashHtml}

                <h1>Tableau de bord</h1>
                <p>Bienvenue sur <strong>Briefly AI</strong> — votre agrégateur de news intelligent.</p>
                <p>Votre Daily Brief quotidien vous attend :</p>
                <a href="/brief" class="btn">Lire mon brief</a>
            </body>
            </html>
            HTML,
            Response::HTTP_OK,
        );
    }
}
