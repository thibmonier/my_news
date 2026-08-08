<?php

declare(strict_types=1);

use App\Domain\Security\PrivateNetworkGuard;

/*
 * Unit tests — PrivateNetworkGuard (anti-SSRF, OWASP A01)
 *
 * Couvre les bypass identifiés par l'audit /team:security :
 *   - IPv6-mapped IPv4 (::ffff:10.0.0.1, ::ffff:169.254.169.254)
 *   - CGNAT RFC-6598 complet /10 (100.64 – 100.127)
 *   - IPv6 ULA fc00::/7
 */

// ── Doit bloquer ────────────────────────────────────────────────────────────────

dataset('blocked_ips', [
    // IPv4 privé RFC-1918
    'private 10/8' => ['10.0.0.1'],
    'private 172.16/12 bas' => ['172.16.0.1'],
    'private 172.16/12 haut' => ['172.31.255.255'],
    'private 192.168/16' => ['192.168.1.1'],
    // IPv4 réservé
    'loopback 127/8' => ['127.0.0.1'],
    'link-local / metadata cloud' => ['169.254.169.254'],
    'unspecified 0/8' => ['0.0.0.0'],
    // CGNAT RFC-6598 /10 — bornes et milieu
    'cgnat 100.64.0.0' => ['100.64.0.0'],
    'cgnat 100.64.x' => ['100.64.1.1'],
    'cgnat 100.100.x (ex-bypass)' => ['100.100.50.50'],
    'cgnat 100.127.255.255 (borne haute)' => ['100.127.255.255'],
    // IPv6 internes
    'ipv6 loopback ::1' => ['::1'],
    'ipv6 unspecified ::' => ['::'],
    'ipv6 link-local fe80' => ['fe80::1'],
    'ipv6 ULA fc00' => ['fc00::1'],
    'ipv6 ULA fd12' => ['fd12:3456::1'],
    // IPv6-mapped IPv4 (bypass HIGH)
    'ipv4-mapped privé ::ffff:10.0.0.1' => ['::ffff:10.0.0.1'],
    'ipv4-mapped metadata ::ffff:169.254.169.254' => ['::ffff:169.254.169.254'],
    'ipv4-mapped loopback ::ffff:127.0.0.1' => ['::ffff:127.0.0.1'],
    'ipv4-mapped cgnat ::ffff:100.100.1.1' => ['::ffff:100.100.1.1'],
    // Malformé → deny by default
    'chaine non-IP' => ['not-an-ip'],
    'vide' => [''],
]);

test('isBlocked retourne true pour une IP privée/réservée/interne', function (string $ip): void {
    expect(PrivateNetworkGuard::isBlocked($ip))->toBeTrue();
})->with('blocked_ips');

// ── Doit autoriser ──────────────────────────────────────────────────────────────

dataset('public_ips', [
    'google dns' => ['8.8.8.8'],
    'cloudflare' => ['1.1.1.1'],
    'exemple public' => ['93.184.216.34'],
    'juste sous 172.16' => ['172.15.255.255'],
    'juste au-dessus 172.31' => ['172.32.0.1'],
    'juste sous 100.64' => ['100.63.255.255'],
    'juste au-dessus 100.127' => ['100.128.0.0'],
    'ipv6 public' => ['2606:4700:4700::1111'],
    'ipv4-mapped public ::ffff:8.8.8.8' => ['::ffff:8.8.8.8'],
]);

test('isBlocked retourne false pour une IP publique', function (string $ip): void {
    expect(PrivateNetworkGuard::isBlocked($ip))->toBeFalse();
})->with('public_ips');
