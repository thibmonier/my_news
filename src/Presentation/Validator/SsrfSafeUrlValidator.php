<?php

declare(strict_types=1);

namespace App\Presentation\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validateur SSRF-safe pour les URLs de flux RSS/Atom.
 *
 * Protection OWASP A01:2025 (SSRF) : refuse toute URL pointant vers
 * des ressources réseau internes ou des adresses réservées.
 *
 * Défense en profondeur :
 * - Couche 1 : validation formulaire (ce validateur, T-021-07)
 * - Couche 2 : ValidateSourceHandler (HEAD HTTP — T-021-05)
 *
 * Note technique : gethostbyname() retourne le hostname si la résolution
 * échoue. Dans ce cas, on laisse passer (la validation asynchrone bloquera
 * si l'URL est inaccessible). On rejette uniquement les cas certains
 * (IP directement dans l'URL, ou hostname qui résout vers une IP privée).
 */
final class SsrfSafeUrlValidator extends ConstraintValidator
{
    /**
     * Blocs CIDR d'adresses privées/réservées RFC-1918 + RFC-5735 (IPv4).
     * Vérification par préfixe pour éviter une dépendance à ip2long.
     *
     * @var array<string, string> [prefix => description]
     */
    private const BLOCKED_PREFIXES_IPV4 = [
        '10.' => 'RFC-1918 Class A private',
        '172.16.' => 'RFC-1918 Class B private',
        '172.17.' => 'RFC-1918 Class B private',
        '172.18.' => 'RFC-1918 Class B private',
        '172.19.' => 'RFC-1918 Class B private',
        '172.20.' => 'RFC-1918 Class B private',
        '172.21.' => 'RFC-1918 Class B private',
        '172.22.' => 'RFC-1918 Class B private',
        '172.23.' => 'RFC-1918 Class B private',
        '172.24.' => 'RFC-1918 Class B private',
        '172.25.' => 'RFC-1918 Class B private',
        '172.26.' => 'RFC-1918 Class B private',
        '172.27.' => 'RFC-1918 Class B private',
        '172.28.' => 'RFC-1918 Class B private',
        '172.29.' => 'RFC-1918 Class B private',
        '172.30.' => 'RFC-1918 Class B private',
        '172.31.' => 'RFC-1918 Class B private',
        '192.168.' => 'RFC-1918 Class C private',
        '127.' => 'loopback',
        '169.254.' => 'link-local (cloud metadata)',
        '0.' => 'reserved',
        '100.64.' => 'shared address space RFC-6598',
    ];

    /** Hostnames toujours bloqués (résolvent vers loopback ou sont des alias). */
    private const BLOCKED_HOSTNAMES = ['localhost', 'ip6-localhost', 'ip6-loopback'];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SsrfSafeUrl) {
            throw new UnexpectedTypeException($constraint, SsrfSafeUrl::class);
        }

        if (null === $value || '' === $value) {
            return; // @Assert\NotBlank gère la valeur vide
        }

        if (!\is_string($value)) {
            $this->context->buildViolation($constraint->messageInvalidUrl)->addViolation();

            return;
        }

        $parsed = parse_url($value);

        if (false === $parsed || !isset($parsed['host'])) {
            $this->context->buildViolation($constraint->messageEmptyHost)->addViolation();

            return;
        }

        $scheme = strtolower($parsed['scheme'] ?? '');

        // Règle 1 : HTTPS obligatoire
        if ('https' !== $scheme) {
            $this->context->buildViolation($constraint->messageHttpsRequired)->addViolation();

            return;
        }

        $host = strtolower($parsed['host']);

        if ('' === $host) {
            $this->context->buildViolation($constraint->messageEmptyHost)->addViolation();

            return;
        }

        // Règle 2 : hostnames bloqués (localhost, ip6-localhost, ...)
        if (\in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            $this->context->buildViolation($constraint->messageSsrfBlocked)->addViolation();

            return;
        }

        // Règle 3 : si le host est directement une IP, vérifier qu'elle n'est pas privée
        if ($this->isIpAddress($host)) {
            if ($this->isBlockedIpv4($host)) {
                $this->context->buildViolation($constraint->messageSsrfBlocked)->addViolation();

                return;
            }

            // IP publique directement dans l'URL → accepter (ex: CDN avec IP)
            return;
        }

        // Règle 4 : résolution DNS du hostname (best-effort)
        // gethostbyname() retourne le hostname si résolution impossible
        $resolvedIp = gethostbyname($host);

        if ($resolvedIp !== $host && $this->isBlockedIpv4($resolvedIp)) {
            $this->context->buildViolation($constraint->messageSsrfBlocked)->addViolation();
        }
    }

    private function isIpAddress(string $host): bool
    {
        return false !== filter_var($host, \FILTER_VALIDATE_IP);
    }

    private function isBlockedIpv4(string $ip): bool
    {
        if (false === filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            // IPv6 ::1 (loopback) — vérification simple
            return '::1' === $ip || str_starts_with($ip, 'fe80:'); // link-local IPv6
        }

        foreach (array_keys(self::BLOCKED_PREFIXES_IPV4) as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
