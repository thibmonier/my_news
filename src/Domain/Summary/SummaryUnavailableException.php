<?php

declare(strict_types=1);

namespace App\Domain\Summary;

/**
 * Exception Domaine — Le fournisseur IA de condensés est temporairement indisponible (US-004).
 *
 * Levée par SummaryClientInterface quand :
 * - Timeout dépassé (> 15s)
 * - Erreur réseau vers l'API
 * - Erreur HTTP 5xx de l'API
 * - Réponse vide ou malformée
 *
 * ArticleSummaryService traduit cette exception en fallback (circuit breaker puis extrait RSS).
 * Jamais de stacktrace exposée à l'utilisateur (OWASP #7).
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
final class SummaryUnavailableException extends \RuntimeException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
