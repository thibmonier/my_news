<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Exception levée quand une valeur de catégorie inconnue est lue en base (US-005).
 *
 * Scénario US-005/erreur 1 : valeur 'BREAKING_NEWS' non présente dans l'enum.
 * La BriefStory concernée est exclue du brief — les 2 autres restent affichées.
 *
 * SÉCURITÉ OWASP #9 :
 * - Le log ERROR contient article_id (UUID) et la valeur invalide
 * - SANS données personnelles (email, IP, session — INV-6)
 *
 * PHP pur — aucun import Symfony/Doctrine.
 * Constitution §4 : entités Domain = classes PHP pures.
 */
final class InvalidCategoryException extends \RuntimeException
{
    public function __construct(
        /** UUID de l'article concerné (pour le log ERROR — sans PII). */
        public readonly string $articleId,
        /** Valeur invalide lue en base. */
        public readonly string $invalidValue,
    ) {
        parent::__construct(
            \sprintf('Catégorie invalide "%s" pour l\'article %s', $invalidValue, $articleId),
        );
    }
}
