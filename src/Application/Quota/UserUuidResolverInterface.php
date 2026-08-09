<?php

declare(strict_types=1);

namespace App\Application\Quota;

/**
 * Port secondaire — Résolution de l'UUID de l'utilisateur courant.
 *
 * Permet aux State Processors de synthèse (Presentation) d'obtenir l'UUID de
 * l'utilisateur authentifié sans dépendre directement de Symfony Security,
 * garantissant la testabilité en unit test (stub PHP anonyme).
 *
 * Implémentée par SecurityUserUuidResolver (Infrastructure/User).
 *
 * Retourne null si aucun utilisateur n'est authentifié.
 *
 * PHP pur — AUCUN import Symfony.
 * Constitution §4 : ports Application = interfaces PHP pures.
 * Deptrac : Application → Domain uniquement.
 */
interface UserUuidResolverInterface
{
    /**
     * Retourne l'UUID de l'utilisateur authentifié (RFC 4122),
     * ou null si aucun utilisateur n'est authentifié ou identifiable.
     */
    public function getCurrentUserUuid(): ?string;
}
