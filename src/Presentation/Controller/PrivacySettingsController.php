<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\Privacy\PrivacySettingsService;
use App\Domain\User\IdentifiableUserInterface;
use App\Presentation\Security\PrivacySettingsVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur — Réglages de confidentialité RGPD (US-035 T-035-07).
 *
 * Routes :
 *   GET   /settings/privacy         — Affiche la page avec les 3 interrupteurs
 *   PATCH /settings/privacy         — Met à jour les préférences (JSON body + CSRF)
 *   GET   /settings/privacy/export  — Télécharge l'export RGPD Article 20 (JSON)
 *
 * Sécurité :
 *   - `#[IsGranted('ROLE_USER')]` : accès réservé aux utilisateurs authentifiés
 *   - Non authentifié → 302 vers /login?redirect_to=/settings/privacy
 *   - `PrivacySettingsVoter::EDIT` : l'utilisateur ne peut modifier QUE ses propres réglages
 *   - PATCH : token CSRF validé depuis le body JSON (`_token`) — identifiant 'privacy_settings'
 *   - Validation whitelist des valeurs booléennes entrantes
 *
 * Export RGPD (Article 20) :
 *   Format JSON : `{"privacy_settings": {...}}` avec Content-Disposition: attachment.
 *   Téléchargement synchrone (< 10 s, pas de processing asynchrone en v1).
 *
 * Couche Presentation — dépend de Application + Domain (deptrac conforme).
 */
#[IsGranted('ROLE_USER')]
#[Route('/settings/privacy')]
final class PrivacySettingsController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'privacy_settings';

    public function __construct(
        private readonly PrivacySettingsService $privacySettingsService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /**
     * GET /settings/privacy — Affiche la page avec les 3 interrupteurs et le token CSRF.
     */
    #[Route('', name: 'app_settings_privacy', methods: ['GET'])]
    public function show(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof IdentifiableUserInterface) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $this->denyAccessUnlessGranted(PrivacySettingsVoter::EDIT, $user);

        $settings = $this->privacySettingsService->getSettings($user->getUserUuid());
        $csrfToken = $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue();

        return $this->render('settings/privacy.html.twig', [
            'settings' => $settings,
            'csrf_token' => $csrfToken,
        ]);
    }

    /**
     * PATCH /settings/privacy — Met à jour les préférences depuis le body JSON.
     *
     * Body JSON attendu :
     *   { "_token": "<csrf>", "analytics_opt_in": bool, "personalized_recs": bool, "search_engine_indexing": bool }
     *
     * Retourne un Turbo Stream toast "Préférences enregistrées" en cas de succès.
     */
    #[Route('', name: 'app_settings_privacy_update', methods: ['PATCH'])]
    public function update(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof IdentifiableUserInterface) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $this->denyAccessUnlessGranted(PrivacySettingsVoter::EDIT, $user);

        // Lire le body JSON
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        // Valider le token CSRF (whitelist — sécurité OWASP)
        $csrfToken = isset($data['_token']) && \is_string($data['_token']) ? $data['_token'] : '';
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $csrfToken))) {
            throw new InvalidCsrfTokenException('Token CSRF invalide.');
        }

        // Validation whitelist des valeurs booléennes
        $analyticsOptIn = isset($data['analytics_opt_in']) ? (bool) $data['analytics_opt_in'] : true;
        $personalizedRecs = isset($data['personalized_recs']) ? (bool) $data['personalized_recs'] : true;
        $searchEngineIndexing = isset($data['search_engine_indexing']) ? (bool) $data['search_engine_indexing'] : true;

        $this->privacySettingsService->updateSettings(
            userId: $user->getUserUuid(),
            analyticsOptIn: $analyticsOptIn,
            personalizedRecs: $personalizedRecs,
            searchEngineIndexing: $searchEngineIndexing,
        );

        // Turbo Stream toast "Préférences enregistrées"
        return new Response(
            $this->renderView('settings/privacy_toast.stream.html.twig'),
            Response::HTTP_OK,
            ['Content-Type' => 'text/vnd.turbo-stream.html'],
        );
    }

    /**
     * GET /settings/privacy/export — Export RGPD Article 20 au format JSON.
     *
     * Retourne un fichier JSON téléchargeable contenant les préférences de confidentialité.
     * Aucune donnée d'autres utilisateurs n'apparaît dans le fichier.
     */
    #[Route('/export', name: 'app_settings_privacy_export', methods: ['GET'])]
    public function export(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof IdentifiableUserInterface) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $this->denyAccessUnlessGranted(PrivacySettingsVoter::EDIT, $user);

        $data = $this->privacySettingsService->exportAsJson($user->getUserUuid());

        $json = json_encode(['privacy_settings' => $data], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return new Response(
            $json,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="privacy.json"',
            ],
        );
    }
}
