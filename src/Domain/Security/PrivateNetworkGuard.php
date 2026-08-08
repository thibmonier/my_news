<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Garde anti-SSRF — détecte les IP privées, réservées ou internes (OWASP A01).
 *
 * Source unique de vérité partagée par SsrfSafeUrlValidator (Presentation) et
 * SynthesisService (Application) pour éviter la dérive de logique entre deux
 * implémentations (bug historique : le service ne bloquait pas le CGNAT).
 *
 * Couvre, via inet_pton (comparaison binaire, pas de piège de préfixe texte) :
 * - IPv4 : RFC-1918 (privé), RFC-5735 (réservé : loopback, link-local, 0/8, …),
 *          CGNAT RFC-6598 (100.64.0.0/10)
 * - IPv6 : ::1 (loopback), :: (unspecified), fe80::/10 (link-local),
 *          fc00::/7 (ULA), et les IPv4-mapped ::ffff:a.b.c.d (ré-évalués en IPv4)
 *
 * PHP pur — aucun import framework (Constitution §4, Domain:[]).
 */
final class PrivateNetworkGuard
{
    /**
     * Retourne true si l'IP pointe vers une ressource privée/réservée/interne,
     * ou si la chaîne n'est pas une IP valide (rejet défensif).
     */
    public static function isBlocked(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if (false === $packed) {
            return true; // pas une IP valide → bloquer par défaut (deny by default)
        }

        // IPv4 (4 octets)
        if (4 === \strlen($packed)) {
            return self::isBlockedIpv4($ip);
        }

        // IPv6 (16 octets)
        // IPv4-mapped ::ffff:a.b.c.d — 80 bits à 0 puis 0xffff → ré-évaluer en IPv4
        if (str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            $mapped = inet_ntop(substr($packed, 12, 4));

            return false === $mapped || self::isBlockedIpv4($mapped);
        }

        // ::1 (loopback) et :: (unspecified)
        if ("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01" === $packed
            || str_repeat("\x00", 16) === $packed) {
            return true;
        }

        $byte0 = \ord($packed[0]);
        $byte1 = \ord($packed[1]);

        // fe80::/10 — link-local (10 premiers bits : 1111 1110 10)
        if (0xFE === $byte0 && 0x80 === ($byte1 & 0xC0)) {
            return true;
        }

        // fc00::/7 — Unique Local Addresses (7 premiers bits : 1111 110)
        return 0xFC === ($byte0 & 0xFE);
    }

    private static function isBlockedIpv4(string $ip): bool
    {
        if (false === filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return true; // pas une IPv4 → bloquer par défaut
        }

        // RFC-1918 (privé) + RFC-5735 (réservé : loopback, link-local, 0/8, …)
        if (false === filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        // CGNAT RFC-6598 : 100.64.0.0/10 = 100.64.0.0 – 100.127.255.255
        // (non couvert par NO_RES_RANGE ; comparaison sur entier non signé)
        $long = sprintf('%u', ip2long($ip));

        return $long >= 1681915904 && $long <= 1686110207;
    }
}
