<?php

declare(strict_types=1);

namespace App\Domain\Synthesis;

/**
 * Port Domaine — Persistance des résultats de synthèse IA.
 *
 * Ségrégation ISP : interface dédiée à l'écriture (Sprint 1).
 * La lecture sera ajoutée via une interface séparée si nécessaire (YAGNI).
 *
 * Implémenté par App\Infrastructure\Synthesis\Persistence\DoctrineSynthesisResultRepository.
 *
 * Couche Domain — PHP pur, aucun import Symfony/Doctrine.
 */
interface SynthesisResultRepositoryInterface
{
    /**
     * Persiste un résultat de synthèse IA.
     *
     * Traçabilité analytics Sprint 1 : appel systématique après chaque génération.
     * Pas de déduplication (url_hash non-unique en Sprint 1 — cache Redis en US-012).
     *
     * @param SynthesisResult $result Résultat à persister (id, url_hash, content, key_points, sources)
     */
    public function save(SynthesisResult $result): void;
}
