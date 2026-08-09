<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * Interface Domain — Utilisateur portant un UUID interne.
 *
 * Permet à la couche Presentation (State Processors de synthèse, etc.) d'obtenir
 * l'UUID de l'utilisateur connecté sans dépendre de DoctrineUserEntity
 * (couche Infrastructure), garantissant la conformité deptrac.
 *
 * Implémentée par :
 * - DoctrineUserEntity (Infrastructure/User/Persistence)
 *
 * Convention d'utilisation en Presentation :
 * ```php
 * $user = $security->getUser();
 * if (!$user instanceof IdentifiableUserInterface) {
 *     throw new AccessDeniedException();
 * }
 * $uuid = $user->getUserUuid();
 * ```
 *
 * PHP pur — AUCUN import Symfony/Doctrine.
 * Constitution §4 : interfaces Domain = PHP pur.
 */
interface IdentifiableUserInterface
{
    /**
     * Retourne l'UUID interne de l'utilisateur (RFC 4122, v4 ou v7).
     * N'est jamais une adresse email, une adresse IP ou une information personnelle.
     */
    public function getUserUuid(): string;
}
