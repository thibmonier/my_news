<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\User\Profile\EmailAlreadyInUseException;
use App\Application\User\Profile\EmailChangeService;
use App\Application\User\Profile\UpdateProfileService;
use App\Domain\User\UserProfileInterface;
use App\Presentation\Form\ProfileFormData;
use App\Presentation\Form\ProfileFormType;
use App\Presentation\Security\ProfileVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur — Gestion du profil utilisateur (US-032).
 *
 * Routes :
 *   GET  /profile/edit              — Affiche le formulaire de profil
 *   POST /profile/edit              — Traite la soumission du formulaire
 *   GET  /profile/confirm-email/{token} — Confirme un changement d'email
 *
 * Sécurité :
 *   - `#[IsGranted('ROLE_USER')]` : accès réservé aux utilisateurs authentifiés
 *   - `ProfileVoter::EDIT` : l'utilisateur ne peut modifier que son propre profil
 *   - Token CSRF intégré dans ProfileFormType (Symfony Form par défaut)
 *
 * Flux email (double opt-in) :
 *   Si l'email soumis diffère de l'email courant →
 *     EmailChangeService::requestChange() → email_pending stocké, email courant inchangé,
 *     email de confirmation envoyé à la NOUVELLE adresse.
 *   Confirmation via GET /profile/confirm-email/{token} →
 *     email ← email_pending, session invalidée (l'identifiant a changé).
 *
 * PHPDoc RGPD :
 *   Jamais d'email dans les logs — UUID uniquement (ProfileVoter + EmailChangeService).
 *
 * Couche Presentation — dépend de Application + Domain (deptrac).
 */
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly UpdateProfileService $updateProfileService,
        private readonly EmailChangeService $emailChangeService,
    ) {
    }

    /**
     * GET /profile/edit  — Affiche le formulaire pré-rempli avec les valeurs actuelles.
     * POST /profile/edit — Traite la soumission et persiste les changements.
     */
    #[Route('/profile/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof UserProfileInterface) {
            throw $this->createAccessDeniedException('Profil inaccessible.');
        }

        $this->denyAccessUnlessGranted(ProfileVoter::EDIT, $user);

        // Pré-remplir le formulaire avec les valeurs actuelles de l'utilisateur
        $formData = new ProfileFormData(
            fullName: $user->getFullName(),
            bio: $user->getBio(),
            email: $user->getEmail(),
        );

        $form = $this->createForm(ProfileFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submittedEmail = $formData->email;
            $emailChanged = mb_strtolower(trim($submittedEmail)) !== mb_strtolower(trim($user->getEmail()));

            if ($emailChanged) {
                // Changement d'email : vérification unicité + double opt-in
                try {
                    $this->emailChangeService->requestChange(
                        userId: $user->getUserUuid(),
                        newEmail: $submittedEmail,
                    );

                    $this->addFlash(
                        'success',
                        \sprintf('Un email de confirmation a été envoyé à %s.', htmlspecialchars($submittedEmail, \ENT_QUOTES | \ENT_HTML5)),
                    );
                } catch (EmailAlreadyInUseException) {
                    $form->get('email')->addError(
                        new \Symfony\Component\Form\FormError('Cette adresse email est déjà associée à un compte Briefly AI.'),
                    );

                    return $this->render('profile/edit.html.twig', [
                        'form' => $form,
                        'user' => $user,
                    ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
                }

                // Mettre à jour le nom et la bio même si l'email change
                $this->updateProfileService->execute(
                    userId: $user->getUserUuid(),
                    fullName: $formData->fullName,
                    bio: $formData->bio,
                );

                return $this->redirectToRoute('app_profile_edit');
            }

            // Pas de changement d'email : mise à jour nom + bio uniquement
            $this->updateProfileService->execute(
                userId: $user->getUserUuid(),
                fullName: $formData->fullName,
                bio: $formData->bio,
            );

            $this->addFlash('success', 'Profil mis à jour avec succès.');

            return $this->redirectToRoute('app_profile_edit');
        }

        $statusCode = $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('profile/edit.html.twig', [
            'form' => $form,
            'user' => $user,
        ], new Response('', $statusCode));
    }

    /**
     * GET /profile/confirm-email/{token} — Confirme un changement d'email via le token reçu.
     *
     * Après confirmation : l'email est mis à jour en base, la session est invalidée
     * (Symfony Security utilise l'email comme identifiant — changement d'identifiant = logout).
     */
    #[Route('/profile/confirm-email/{token}', name: 'app_profile_confirm_email', methods: ['GET'])]
    public function confirmEmail(string $token, Request $request): Response
    {
        $success = $this->emailChangeService->confirmChange($token);

        if ($success) {
            // Invalider la session : l'identifiant (email) a changé
            $request->getSession()->invalidate();
            $this->addFlash('success', 'Email mis à jour avec succès. Veuillez vous reconnecter.');
        } else {
            $this->addFlash('error', 'Le lien de confirmation est invalide ou a expiré.');
        }

        return $this->redirectToRoute('app_profile_edit');
    }
}
