<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur — Dashboard utilisateur.
 *
 * Route : GET /dashboard
 * Accès : ROLE_USER requis (constitution §6 : deny by default via access_control).
 *
 * Rendu Twig (templates/dashboard/index.html.twig) consommant le design system
 * partagé (base.html.twig + tokens Stitch) — cf ADR-011.
 *
 * Couche Presentation — dépend uniquement de l'infrastructure Symfony HTTP.
 */
#[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('dashboard/index.html.twig');
    }
}
