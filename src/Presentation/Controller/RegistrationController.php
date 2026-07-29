<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\User\Register\EmailAlreadyExistsException;
use App\Application\User\Register\RegisterUserCommand;
use App\Application\User\Register\RegisterUserHandler;
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
 * Note Sprint 1 : réponse HTML inline (Twig non installé en Sprint 1).
 * À migrer vers un template Twig + Stimulus quand symfony/twig-bundle sera ajouté.
 *
 * Couche Presentation — dépend de Application + Domain (deptrac).
 */
final class RegistrationController
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
        $successMessage = null;

        if ($request->isMethod('POST')) {
            [$errors, $formData, $loginOrRedirect] = $this->handlePost($request);

            if (null !== $loginOrRedirect) {
                return $loginOrRedirect;
            }
        }

        $csrfToken = $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue();

        // Duplicate-email is a "soft" case: return 200 (anti-enumeration OWASP A07).
        // Real validation errors (CSRF, format, missing fields) still return 422.
        $validationErrors = array_diff_key($errors, ['_email_exists' => null]);

        return new Response(
            content: $this->renderForm($formData, $errors, $csrfToken),
            status: [] !== $validationErrors ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
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

    // ── Vue inline (Sprint 1 — Twig non installé) ─────────────────────────────

    /**
     * @param array<string,string> $formData
     * @param array<string,string> $errors
     */
    private function renderForm(array $formData, array $errors, string $csrfToken): string
    {
        $email = htmlspecialchars($formData['email'] ?? '', \ENT_QUOTES | \ENT_HTML5);
        $fullName = htmlspecialchars($formData['fullName'] ?? '', \ENT_QUOTES | \ENT_HTML5);
        $token = htmlspecialchars($csrfToken, \ENT_QUOTES | \ENT_HTML5);

        $errorHtml = static function (array $errors, string $field): string {
            $value = $errors[$field] ?? null;
            if (!\is_string($value) || '' === $value) {
                return '';
            }
            $msg = htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5);

            return \sprintf('<p class="error" role="alert" id="error-%s">%s</p>', $field, $msg);
        };

        $csrfError = $errors['_csrf'] ?? null;
        $globalError = null !== $csrfError ? \sprintf(
            '<div class="error global" role="alert">%s</div>',
            htmlspecialchars($csrfError, \ENT_QUOTES | \ENT_HTML5),
        ) : '';

        // Anti-enumeration: duplicate email shown as informational message at HTTP 200.
        $emailExistsMsg = $errors['_email_exists'] ?? null;
        $emailExistsHtml = null !== $emailExistsMsg ? \sprintf(
            '<div class="info-msg" role="status">%s</div>',
            htmlspecialchars($emailExistsMsg, \ENT_QUOTES | \ENT_HTML5),
        ) : '';

        return <<<HTML
            <!DOCTYPE html>
            <html lang="fr">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Inscription — Briefly AI</title>
                <style>
                    body { font-family: system-ui, sans-serif; max-width: 480px; margin: 2rem auto; padding: 1rem; }
                    label { display: block; margin-top: 1rem; font-weight: bold; }
                    input[type="email"], input[type="text"], input[type="password"] {
                        width: 100%; padding: .5rem; margin-top: .25rem; box-sizing: border-box;
                        border: 1px solid #ccc; border-radius: 4px;
                    }
                    .error { color: #dc2626; font-size: .875rem; margin-top: .25rem; }
                    .error.global { background: #fee2e2; padding: .75rem; border-radius: 4px; }
                    .info-msg { background: #dbeafe; color: #1e40af; padding: .75rem; border-radius: 4px; margin-bottom: 1rem; }
                    button[type="submit"] {
                        margin-top: 1.5rem; width: 100%; padding: .75rem;
                        background: #10B981; color: white; border: none; border-radius: 4px;
                        font-size: 1rem; cursor: pointer;
                    }
                    button[type="submit"]:hover { background: #059669; }
                    .toggle-btn { background: none; border: none; cursor: pointer; font-size: .875rem; color: #6b7280; }
                    .password-wrapper { position: relative; }
                    .consent { display: flex; align-items: flex-start; gap: .5rem; margin-top: 1rem; }
                    .consent input { margin-top: .2rem; width: auto; }
                    .links { text-align: center; margin-top: 1rem; }
                </style>
            </head>
            <body>
                <h1>Créer un compte</h1>
                {$globalError}
                {$emailExistsHtml}
                <form method="POST" action="/register" novalidate>
                    <input type="hidden" name="_csrf_token" value="{$token}">

                    <label for="email">Adresse email *</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="{$email}"
                        required
                        autocomplete="email"
                        aria-describedby="error-email"
                    >
                    {$errorHtml($errors, 'email')}

                    <label for="fullName">Nom complet *</label>
                    <input
                        type="text"
                        id="fullName"
                        name="fullName"
                        value="{$fullName}"
                        required
                        autocomplete="name"
                        aria-describedby="error-fullName"
                    >
                    {$errorHtml($errors, 'fullName')}

                    <label for="plainPassword">Mot de passe *</label>
                    <div class="password-wrapper">
                        <input
                            type="password"
                            id="plainPassword"
                            name="plainPassword"
                            required
                            autocomplete="new-password"
                            aria-describedby="error-plainPassword"
                            data-controller="password-toggle"
                            data-password-toggle-target="input"
                        >
                        <button
                            type="button"
                            class="toggle-btn"
                            data-action="password-toggle#toggle"
                            data-password-toggle-target="button"
                            aria-label="Afficher le mot de passe"
                        >Afficher</button>
                    </div>
                    <small>Minimum 12 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial.</small>
                    {$errorHtml($errors, 'plainPassword')}

                    <div class="consent">
                        <input
                            type="checkbox"
                            id="consentCgu"
                            name="consentCgu"
                            value="1"
                            required
                            aria-describedby="error-consentCgu"
                        >
                        <label for="consentCgu" style="font-weight: normal; margin-top: 0;">
                            J'accepte les
                            <a href="/legal/cgu">Conditions Générales d'Utilisation</a>
                            et la
                            <a href="/legal/privacy">Politique de confidentialité</a> *
                        </label>
                    </div>
                    {$errorHtml($errors, 'consentCgu')}

                    <button type="submit">Créer mon compte</button>
                </form>

                <div class="links">
                    <p>Déjà un compte ? <a href="/login">Se connecter</a></p>
                </div>

                <!-- Stimulus controller password-toggle (Sprint 1 stub — nécessite @symfony/stimulus-bridge) -->
                <script>
                    (function() {
                        const btn = document.querySelector('[data-action="password-toggle#toggle"]');
                        const input = document.querySelector('[data-password-toggle-target="input"]');
                        if (!btn || !input) return;
                        btn.addEventListener('click', function() {
                            const isPassword = input.type === 'password';
                            input.type = isPassword ? 'text' : 'password';
                            btn.textContent = isPassword ? 'Masquer' : 'Afficher';
                            btn.setAttribute('aria-label', isPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
                        });
                    })();
                </script>
            </body>
            </html>
            HTML;
    }
}
