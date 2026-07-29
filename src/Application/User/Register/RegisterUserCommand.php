<?php

declare(strict_types=1);

namespace App\Application\User\Register;

/**
 * Commande — Inscription d'un nouvel utilisateur.
 *
 * Objet de transfert entre Presentation et Application.
 * Immutable : toutes les propriétés sont en readonly.
 *
 * L'identifiant UUID v7 est généré par la couche Presentation
 * (utilise symfony/uid) et passé ici pour garantir l'idempotence.
 *
 * Constitution §4 : pas de logique métier dans les Commands.
 * OWASP #9 : mot de passe marqué SensitiveParameter.
 */
final class RegisterUserCommand
{
    /**
     * @param string $userId UUID v7 pré-généré (non séquentiel — constitution §6)
     * @param string $email Adresse email (sera validée par l'handler)
     * @param string $plainPassword Mot de passe en clair (sera haché par l'handler, jamais logué)
     * @param string $fullName Nom complet de l'utilisateur
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $email,
        #[\SensitiveParameter]
        public readonly string $plainPassword,
        public readonly string $fullName,
    ) {
    }
}
