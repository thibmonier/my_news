<?php

declare(strict_types=1);

namespace App\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Presentation\StateProcessor\QuotaStateProcessor;
use App\Presentation\StateProcessor\UrlSynthesisProcessor;

/**
 * Ressource API Platform — Synthèse IA d'un article.
 *
 * Deux opérations :
 *
 * 1. POST /api/v1/articles/{id}/synthesize  [US-033 Walking Skeleton]
 *    Input : ID article depuis le template URI
 *    Output: synthèse stub "BRIEFLY AI:" + quota utilisé/restant
 *    Processor: QuotaStateProcessor → SynthesisStubProcessor (Sprint 1 placeholder)
 *
 * 2. POST /api/v1/synthesis  [US-010 Mistral réel]
 *    Input : corps JSON { "url": "https://..." }
 *    Output: synthèse Mistral complète + keyPoints + sources + originalUrl + isPartial
 *    Processor: UrlSynthesisProcessor (quota intégré + appel Mistral réel)
 *
 * Auth  : ROLE_USER requis sur les deux opérations (constitution §6 : deny by default)
 *
 * Couche Presentation (deptrac : Presentation → Domain, Application).
 */
#[ApiResource(
    routePrefix: '/v1',
    operations: [
        // ── US-033 : synthèse stub par ID d'article ────────────────────────────
        new Post(
            uriTemplate: '/articles/{id}/synthesize',
            security: 'is_granted("ROLE_USER")',
            processor: QuotaStateProcessor::class,
            output: SynthesisResource::class,
        ),
        // ── US-010 : synthèse Mistral réelle par URL ───────────────────────────
        new Post(
            uriTemplate: '/synthesis',
            security: 'is_granted("ROLE_USER")',
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
     * @param string $content Condensé IA préfixé "BRIEFLY AI:"
     * @param string[] $keyPoints 3 points clés numérotés 01/02/03 (US-010)
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
