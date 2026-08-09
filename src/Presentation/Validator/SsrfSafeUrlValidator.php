<?php

declare(strict_types=1);

namespace App\Presentation\Validator;

use App\Domain\Security\PrivateNetworkGuard;
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

        // Strip des crochets IPv6 littéraux ([::1] → ::1) pour la validation IP.
        $host = strtolower(trim($parsed['host'], '[]'));

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
            if (PrivateNetworkGuard::isBlocked($host)) {
                $this->context->buildViolation($constraint->messageSsrfBlocked)->addViolation();

                return;
            }

            // IP publique directement dans l'URL → accepter (ex: CDN avec IP)
            return;
        }

        // Règle 4 : résolution DNS du hostname (best-effort)
        // gethostbyname() retourne le hostname si résolution impossible
        $resolvedIp = gethostbyname($host);

        if ($resolvedIp !== $host && PrivateNetworkGuard::isBlocked($resolvedIp)) {
            $this->context->buildViolation($constraint->messageSsrfBlocked)->addViolation();
        }
    }

    private function isIpAddress(string $host): bool
    {
        return false !== filter_var($host, \FILTER_VALIDATE_IP);
    }
}
