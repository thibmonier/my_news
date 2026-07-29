<?php

declare(strict_types=1);

namespace App\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Presentation\StateProcessor\QuotaStateProcessor;
use App\Presentation\StateProcessor\UrlSynthesisProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ressource API Platform — Synthèse IA d'un article (US-010 + US-011).
 *
 * Deux opérations :
 *
 * 1. POST /api/v1/articles/{id}/synthesize  [US-033 Walking Skeleton]
 *    Input : ID article depuis le template URI
 *    Output: synthèse stub "BRIEFLY AI:" + quota utilisé/restant
 *    Processor: QuotaStateProcessor → SynthesisStubProcessor (Sprint 1 placeholder)
 *
 * 2. POST /api/v1/synthesis  [US-010 Mistral réel + US-011 multi-niveaux]
 *    Input : corps JSON { "url": "https://...", "level": "concise|detailed|narrative" }
 *    Output: synthèse Mistral complète + level + keyPoints + sources + originalUrl + isPartial
 *    Processor: UrlSynthesisProcessor (quota intégré + appel Mistral réel)
 *
 * Validation niveau (US-011) :
 * - level ∈ ['concise', 'detailed', 'narrative'] → HTTP 422 si invalide
 * - level nullable/absent → interprété comme 'concise' (défaut) par UrlSynthesisProcessor
 *
 * Auth  : ROLE_USER requis sur les deux opérations (constitution §6 : deny by default).
 *
 * Couche Presentation (deptrac : Presentation → Domain, Application).
 */
#[ApiResource(
    routePrefix: '/api/v1',
    operations: [
        // ── US-033 : synthèse stub par ID d'article ────────────────────────────
        // Auth : ROLE_USER enforced at firewall level (access_control security.yaml).
        new Post(
            uriTemplate: '/articles/{id}/synthesize',
            processor: QuotaStateProcessor::class,
            output: SynthesisResource::class,
        ),
        // ── US-010 / US-011 : synthèse Mistral réelle par URL + niveau ─────────
        new Post(
            uriTemplate: '/synthesis',
            processor: UrlSynthesisProcessor::class,
            output: SynthesisResource::class,
        ),
    ],
)]
final class SynthesisResource
{
    /**
     * @param string $id UUID v4 de la synthèse générée
     * @param string $articleId ID de l'article synthétisé (US-033 — depuis template URI)
     * @param string $url URL source fournie par le client (US-010 — depuis body)
     * @param string|null $level Niveau de synthèse demandé (US-011) — 'concise', 'detailed' ou 'narrative'
     * @param string $content Condensé IA préfixé "BRIEFLY AI:"
     * @param string[] $keyPoints Points clés numérotés 01/02/03[/04/05] (US-011)
     * @param string[] $sources Sources citées (US-010)
     * @param string $originalUrl URL source originale pour lien "OUVRIR L'ORIGINAL" (US-010)
     * @param bool $isPartial true si contenu source partiel / paywall (US-010)
     * @param string $generatedAt Horodatage UTC ISO 8601
     * @param int $quotaUsed Synthèses consommées aujourd'hui (UTC)
     * @param int $quotaRemaining Synthèses restantes aujourd'hui (UTC)
     */
    public function __construct(
        public readonly string $id = '',
        public readonly string $articleId = '',
        public readonly string $url = '',

        /**
         * Niveau de synthèse — whitelist stricte (US-011 T-011-06).
         * HTTP 422 si valeur inconnue ; null/absent → 'concise' par défaut dans le processor.
         */
        #[Assert\Choice(
            choices: ['concise', 'detailed', 'narrative'],
            message: 'level must be one of: concise, detailed, narrative',
        )]
        public readonly ?string $level = null,

        public readonly string $content = '',
        /** @var string[] */
        public readonly array $keyPoints = [],
        /** @var string[] */
        public readonly array $sources = [],
        public readonly string $originalUrl = '',
        public readonly bool $isPartial = false,
        public readonly string $generatedAt = '',
        public readonly int $quotaUsed = 0,
        public readonly int $quotaRemaining = 0,
    ) {
    }
}
