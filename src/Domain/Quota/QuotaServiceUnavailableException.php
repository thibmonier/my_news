<?php

declare(strict_types=1);

namespace App\Domain\Quota;

/**
 * Exception levée quand le service de quota Redis est inaccessible.
 *
 * Levée par RedisQuotaCounter (Infrastructure) et remontée jusqu'au
 * les State Processors de synthèse (Presentation) qui retournent HTTP 503 (fail-safe).
 *
 * Fail-safe (US-033 scénario erreur 2) :
 * Redis KO → HTTP 503, aucune synthèse générée, aucun bypass du quota.
 *
 * RGPD : ne contient JAMAIS d'UUID, d'email ou d'adresse IP utilisateur.
 * Logging : loguée en niveau WARN sans données personnelles (constitution §6).
 *
 * PHP pur — AUCUN import Symfony/Doctrine/ApiPlatform.
 * Constitution §4 : exceptions Domain = classes PHP pures.
 */
final class QuotaServiceUnavailableException extends \RuntimeException
{
    public function __construct(string $reason = '', ?\Throwable $previous = null)
    {
        parent::__construct(
            'QuotaService: Redis connection failed'
            . ('' !== $reason ? ' — ' . $reason : ''),
            0,
            $previous,
        );
    }
}
