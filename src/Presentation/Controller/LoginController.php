<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Contrôleur — Page de connexion.
 *
 * Route : GET /login
 *         POST /login (géré par Symfony Security form_login — pas de logique ici)
 *
 * Rendu Twig (templates/security/login.html.twig) consommant le design system
 * partagé (base.html.twig + tokens Stitch) — cf ADR-011.
 *
 * Le jeton CSRF (`_csrf_token`, id « authenticate ») est fourni par la fonction Twig
 * `csrf_token()` — form_login a `enable_csrf: true`.
 *
 * Les erreurs d'authentification sont génériques (AuthenticationUtils) — pas de détail
 * exposé (OWASP #8, anti-énumération).
 *
 * Couche Presentation — dépend uniquement de l'infrastructure Symfony HTTP.
 */
final class LoginController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function __invoke(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }
}
