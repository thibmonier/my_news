<?php

declare(strict_types=1);

namespace App\Domain\Brief;

/**
 * Événement domaine — dispatché quand la sélection du brief échoue (0 articles disponibles).
 *
 * PHP pur — AUCUN import Symfony/Doctrine.
 * Constitution §4 : events Domain = classes PHP pures.
 *
 * SÉCURITÉ OWASP : aucune donnée personnelle dans cet événement.
 * Le champ reason est un code technique, jamais une stacktrace.
 */
final class BriefGenerationFailedEvent
{
    public function __construct(
        /** Date pour laquelle la génération a été tentée */
        public readonly \DateTimeImmutable $targetDate,
        /** Code technique de la raison d'échec (ex: "no_articles_available") */
        public readonly string $reason,
    ) {
    }
}
