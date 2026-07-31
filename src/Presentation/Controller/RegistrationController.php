<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\User\Register\EmailAlreadyExistsException;
use App\Application\User\Register\RegisterUserCommand;
use App\Application\User\Register\RegisterUserHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Contrôleur — Inscription par email.
 *
 * Routes :
 *   GET  /register  — Affiche le formulaire d'inscription
 *   POST /register  — Traite la soumission du formulaire
 *
 * Sécurité :
 *   - Token CSRF vérifié sur chaque POST (OWASP #1)
 *   - Rate limit : 10 tentatives/h/IP via Redis (OWASP #8 — US-030 scénario erreur 2)
 *   - Pas de fuite sur email dupliqué (OWASP #8 — scénario alternatif 2)
 *   - Mot de passe en clair jamais logué (#[\SensitiveParameter] dans le Handler)
 *
 * Vue : templates/registration/register.html.twig (migré depuis heredoc Sprint 1).
 *
 * Couche Presentation — dépend de Application + Domain (deptrac).
 */
final class RegistrationController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'registration';
    private const PASSWORD_REGEX = '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z\d]).{12,}$/';

    /**
     * @param UserProviderInterface<\Symfony\Component\Security\Core\User\UserInterface> $userProvider
     */
    public function __construct(
        private readonly RegisterUserHandler $handler,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Security $security,
        #[Autowire(service: 'security.user.provider.concrete.app_users')]
        private readonly UserProviderInterface $userProvider,
        #[Autowire(service: 'limiter.registration_by_ip')]
        private readonly RateLimiterFactory $registrationLimiter,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        // Appliquer le rate limit sur les POST (protection anti-spam inscription)
        if ($request->isMethod('POST')) {
            $rateLimitResponse = $this->applyRateLimit($request);
            if (null !== $rateLimitResponse) {
                return $rateLimitResponse;
            }
        }

        $errors = [];
        $formData = [
            'email' => '',
            'fullName' => '',
        ];

        if ($request->isMethod('POST')) {
            [$errors, $formData, $loginOrRedirect] = $this->handlePost($request);

            if (null !== $loginOrRedirect) {
                return $loginOrRedirect;
            }
        }

        // Duplicate-email is a "soft" case: return 200 (anti-enumeration OWASP A07).
        // Real validation errors (CSRF, format, missing fields) still return 422.
        $validationErrors = array_diff_key($errors, ['_email_exists' => null]);

        $response = $this->render('registration/register.html.twig', [
            'formData' => $formData,
            'errors' => $errors,
        ]);

        if ([] !== $validationErrors) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    // ── Traitement POST ────────────────────────────────────────────────────────

    /**
     * @return array{array<string,string>, array<string,string>, Response|null}
     */
    private function handlePost(Request $request): array
    {
        $email = trim((string) $request->request->get('email', ''));
        $fullName = trim((string) $request->request->get('fullName', ''));
        $plainPassword = (string) $request->request->get('plainPassword', '');
        $consentCgu = (bool) $request->request->get('consentCgu', false);
        $csrfToken = (string) $request->request->get('_csrf_token', '');

        $formData = ['email' => $email, 'fullName' => $fullName];

        // --- CSRF ---
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $csrfToken))) {
            return [
                ['_csrf' => 'Token de sécurité invalide. Veuillez recharger la page.'],
                $formData,
                null,
            ];
        }

        // --- Validation ---
        $errors = $this->validate($email, $plainPassword, $fullName, $consentCgu);

        if ([] !== $errors) {
            return [$errors, $formData, null];
        }

        // --- Use case ---
        try {
            $userId = Uuid::v7()->toRfc4122();
            $this->handler->handle(new RegisterUserCommand(
                userId: $userId,
                email: $email,
                plainPassword: $plainPassword,
                fullName: $fullName,
            ));
        } catch (EmailAlreadyExistsException) {
            // Anti-enumeration: use '_email_exists' key so the HTTP response is 200,
            // not 422, preventing account discovery via status code (OWASP A07).
            return [
                [
                    '_email_exists' => 'Un compte existe déjà pour cet email. Connectez-vous ou réinitialisez votre mot de passe.',
                ],
                $formData,
                null,
            ];
        }

        // --- Auto-login ---
        $userEntity = $this->userProvider->loadUserByIdentifier($email);
        $loginResponse = $this->security->login($userEntity, 'form_login', 'main');

        // Flash + redirect
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', 'Bienvenue sur Briefly AI !');
        }

        return [[], $formData, $loginResponse ?? new RedirectResponse('/dashboard')];
    }

    // ── Rate limiting ──────────────────────────────────────────────────────────

    private function applyRateLimit(Request $request): ?Response
    {
        $limiter = $this->registrationLimiter->create($request->getClientIp() ?? '127.0.0.1');
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter();
            $seconds = max(0, $retryAfter->getTimestamp() - time());

            return new Response(
                content: 'Trop de tentatives. Réessayez dans 60 minutes.',
                status: Response::HTTP_TOO_MANY_REQUESTS,
                headers: ['Retry-After' => (string) $seconds],
            );
        }

        return null;
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * @return array<string,string>
     */
    private function validate(
        string $email,
        string $plainPassword,
        string $fullName,
        bool $consentCgu,
    ): array {
        $errors = [];

        if ('' === $email) {
            $errors['email'] = 'L\'adresse email est obligatoire.';
        } elseif (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Veuillez saisir une adresse email valide.';
        } elseif (mb_strlen($email) > 255) {
            $errors['email'] = 'L\'adresse email ne peut pas dépasser 255 caractères.';
        }

        if ('' === $plainPassword) {
            $errors['plainPassword'] = 'Le mot de passe est obligatoire.';
        } elseif (mb_strlen($plainPassword) < 12) {
            $errors['plainPassword'] = 'Le mot de passe doit contenir au minimum 12 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial';
        } elseif (1 !== preg_match(self::PASSWORD_REGEX, $plainPassword)) {
            $errors['plainPassword'] = 'Le mot de passe doit contenir au minimum 12 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial';
        }

        if ('' === $fullName) {
            $errors['fullName'] = 'Le nom complet est obligatoire.';
        } elseif (mb_strlen($fullName) > 255) {
            $errors['fullName'] = 'Le nom complet ne peut pas dépasser 255 caractères.';
        }

        if (!$consentCgu) {
            $errors['consentCgu'] = 'Vous devez accepter les Conditions Générales d\'Utilisation.';
        }

        return $errors;
    }
}
