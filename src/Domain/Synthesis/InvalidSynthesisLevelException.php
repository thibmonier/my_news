<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Exception Domaine — Niveau de synthèse invalide (US-011).
 *
 * Levée par SynthesisLevel::fromString() si la valeur fournie n'est pas
 * l'une des valeurs autorisées : 'concise', 'detailed', 'narrative'.
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
final class InvalidSynthesisLevelException extends \InvalidArgumentException
{
}
