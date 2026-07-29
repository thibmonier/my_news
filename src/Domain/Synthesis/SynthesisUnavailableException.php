<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Exception domaine — Le service de synthèse IA est temporairement indisponible.
 *
 * Levée par MistralClientInterface quand :
 * - Timeout dépassé (> 15s)
 * - Erreur réseau vers l'API Mistral
 * - Erreur HTTP 5xx de l'API Mistral
 *
 * Le handler Presentation traduit cette exception en HTTP 503 sans stacktrace (OWASP A05).
 * L'url_hash (SHA-256) est loggué, jamais l'identifiant utilisateur (RGPD).
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
final class SynthesisUnavailableException extends \RuntimeException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
