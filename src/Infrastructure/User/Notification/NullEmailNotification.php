<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Notification;

use App\Application\User\Profile\EmailNotificationInterface;
use Psr\Log\LoggerInterface;

/**
 * Adaptateur Infrastructure — Implémentation no-op de l'envoi d'email de profil.
 *
 * Utilisée par défaut tant que symfony/mailer n'est pas installé.
 * En production, remplacer par une implémentation Mailer concrète.
 *
 * Log INFO à chaque envoi (traçabilité sans real delivery).
 *
 * Couche Infrastructure — Deptrac: Infrastructure:[Domain, Application].
 */
final class NullEmailNotification implements EmailNotificationInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendEmailConfirmationRequest(string $toEmail, string $confirmUrl): void
    {
        // En production, envoyer un vrai email via symfony/mailer.
        // Pour le moment, log uniquement (symfony/mailer non installé).
        $this->logger->info('profile.email_confirmation_stub', [
            'event' => 'profile.email_confirmation_stub',
            'confirm_url' => $confirmUrl,
            // Note RGPD : l'email de destination n'est PAS loggué ici.
        ]);
    }
}
