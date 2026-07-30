<?php

declare(strict_types=1);

namespace App\Application\User\Profile;

/**
 * Port — Notification email pour le profil utilisateur.
 *
 * Interface Application (DIP) — implémentation dans Infrastructure.
 * Découple l'Application de la bibliothèque de mailing concrète.
 *
 * Constitution §4 : interfaces de ports dans Application, adaptateurs dans Infrastructure.
 */
interface EmailNotificationInterface
{
    /**
     * Envoie un email de confirmation de changement d'adresse email.
     *
     * @param string $toEmail Adresse email de destination (nouvelle adresse)
     * @param string $confirmUrl URL complète de confirmation (avec token, TTL 24h)
     */
    public function sendEmailConfirmationRequest(string $toEmail, string $confirmUrl): void;
}
