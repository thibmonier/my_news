<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * Interface Domain — Profil utilisateur éditable.
 *
 * Étend IdentifiableUserInterface pour exposer les données de profil
 * sans dépendre de l'infrastructure (DoctrineUserEntity).
 *
 * Permet à la couche Presentation (ProfileController, ProfileVoter)
 * de lire et écrire le profil via une interface Domain pure.
 *
 * PHP pur — AUCUN import Symfony/Doctrine.
 * Constitution §4 : interfaces Domain = PHP pur.
 */
interface UserProfileInterface extends IdentifiableUserInterface
{
    /**
     * Retourne l'adresse email actuelle (confirmée) de l'utilisateur.
     * N'est jamais l'adresse email en attente de validation.
     */
    public function getEmail(): string;

    /**
     * Retourne le nom complet de l'utilisateur.
     */
    public function getFullName(): string;

    /**
     * Retourne la bio professionnelle (280 caractères max), ou null si non renseignée.
     */
    public function getBio(): ?string;

    /**
     * Retourne l'adresse email en attente de validation (double opt-in), ou null.
     * Tant que ce champ est non null, l'email courant reste getEmail().
     */
    public function getEmailPending(): ?string;
}
