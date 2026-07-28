<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Exception domaine — URL fournie invalide ou SSRF détecté.
 *
 * Levée par SynthesisService quand :
 * - URL malformée (filter_var FILTER_VALIDATE_URL échoue)
 * - Schéma non autorisé (ni http ni https)
 * - IP résolue en plage privée RFC1918 (SSRF — OWASP A01)
 * - Hostname localhost / loopback (::1 / 127.x.x.x)
 *
 * Le handler Presentation traduit cette exception en HTTP 422 (OWASP A05 : message générique).
 * Aucun appel réseau vers Mistral n'est effectué quand cette exception est levée.
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
final class InvalidSynthesisUrlException extends \InvalidArgumentException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
