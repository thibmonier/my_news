<?php

declare(strict_types=1);

namespace App\Application\User\Profile;

use App\Domain\User\Email;
use App\Domain\User\UserRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service Application — Changement d'email en double opt-in.
 *
 * Flux :
 *  1. requestChange() : génère un token UUID v4 (TTL 24h), stocke email_pending + token,
 *     envoie l'email de confirmation à la NOUVELLE adresse. L'email courant reste inchangé.
 *  2. confirmChange() : vérifie le token, applique le changement (email ← email_pending),
 *     vide les champs temporaires.
 *
 * Sécurité :
 *  - Jamais d'email loggué (RGPD) — UUID uniquement dans les logs.
 *  - Token UUID v4 (128 bits d'entropie, non séquentiel).
 *  - TTL 24h contrôlé par email_pending_expires_at.
 *
 * Couche Application — dépend de Domain + Psr\Log (deptrac).
 */
final class EmailChangeService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly EmailNotificationInterface $emailNotification,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Initie un changement d'email : stocke email_pending + token, envoie email de confirmation.
     *
     * @param string $userId UUID RFC 4122 de l'utilisateur demandeur
     * @param string $newEmail Nouvelle adresse email souhaitée
     *
     * @throws EmailAlreadyInUseException si le nouvel email est déjà utilisé par un autre compte
     * @throws \RuntimeException si l'utilisateur est introuvable
     * @throws \InvalidArgumentException si le format de l'email est invalide
     */
    public function requestChange(string $userId, string $newEmail): void
    {
        $newEmailVo = new Email($newEmail);

        // Vérifier unicité : l'email ne doit pas appartenir à un autre utilisateur
        $existingUser = $this->userRepository->findByEmail($newEmailVo);

        if (null !== $existingUser && $existingUser->getId() !== $userId) {
            throw new EmailAlreadyInUseException();
        }

        $user = $this->userRepository->findById($userId);

        if (null === $user) {
            throw new \RuntimeException(\sprintf('Utilisateur introuvable : %s', $userId));
        }

        // Si l'email soumis est identique à l'email courant, rien à faire
        if ($user->getEmail()->getValue() === $newEmailVo->getValue()) {
            return;
        }

        $token = Uuid::v4()->toRfc4122();
        $expiresAt = new \DateTimeImmutable('+24 hours', new \DateTimeZone('UTC'));

        $user->requestEmailChange($newEmailVo->getValue(), $token, $expiresAt);
        $this->userRepository->save($user);

        $confirmUrl = '/profile/confirm-email/' . $token;
        $this->emailNotification->sendEmailConfirmationRequest($newEmailVo->getValue(), $confirmUrl);

        $this->logger->info('profile.email_change_requested', [
            'event' => 'profile.email_change_requested',
            'user_uuid' => $userId,
        ]);
    }

    /**
     * Confirme le changement d'email via le token de confirmation.
     *
     * @param string $token Token UUID v4 reçu dans le lien de confirmation
     *
     * @return bool true si la confirmation a réussi, false si token invalide ou expiré
     */
    public function confirmChange(string $token): bool
    {
        $user = $this->userRepository->findByEmailPendingToken($token);

        if (null === $user) {
            $this->logger->warning('profile.email_confirm_invalid_token', [
                'event' => 'profile.email_confirm_invalid_token',
            ]);

            return false;
        }

        $expiresAt = $user->getEmailPendingExpiresAt();

        if (null === $expiresAt || $expiresAt < new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            $this->logger->warning('profile.email_confirm_expired_token', [
                'event' => 'profile.email_confirm_expired_token',
                'user_uuid' => $user->getId(),
            ]);

            return false;
        }

        $user->confirmEmailChange();
        $this->userRepository->save($user);

        $this->logger->info('profile.email_changed', [
            'event' => 'profile.email_changed',
            'user_uuid' => $user->getId(),
        ]);

        return true;
    }
}
