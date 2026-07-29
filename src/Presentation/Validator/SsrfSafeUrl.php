<?php

declare(strict_types=1);

namespace App\Presentation\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Contrainte de validation SSRF-safe pour les URLs de flux RSS/Atom.
 *
 * Protection OWASP A01:2025 (SSRF consolidé dans Broken Access Control).
 *
 * Règles :
 * 1. Schéma HTTPS obligatoire (HTTP rejeté)
 * 2. Rejet des IPs RFC-1918 : 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
 * 3. Rejet loopback : 127.0.0.0/8, ::1
 * 4. Rejet link-local : 169.254.0.0/16 (metadata cloud AWS/Azure/GCP)
 * 5. Rejet des hostnames résolus vers IP privée/réservée
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class SsrfSafeUrl extends Constraint
{
    public string $messageHttpsRequired = 'Seules les sources HTTPS sont autorisées.';
    public string $messageInvalidUrl = 'L\'URL fournie n\'est pas valide.';
    public string $messageSsrfBlocked = 'Cette URL pointe vers une ressource réseau interne non autorisée.';
    public string $messageEmptyHost = 'L\'URL doit contenir un nom d\'hôte valide.';
}
