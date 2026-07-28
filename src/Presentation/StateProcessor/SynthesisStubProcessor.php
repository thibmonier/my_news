<?php

declare(strict_types=1);

namespace App\Presentation\StateProcessor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Presentation\ApiResource\SynthesisResource;
use Symfony\Component\Uid\Uuid;

/**
 * State Processor — Génération de synthèse IA (stub Sprint 1).
 *
 * Placeholder pour US-010 (intégration Mistral). Retourne une synthèse
 * statique préfixée "BRIEFLY AI:" sans appel HTTP externe.
 *
 * Sprint 2 : ce processor sera remplacé par MistralSynthesisProcessor
 * qui appellera l'API Mistral avec le contenu de l'article.
 *
 * Couche Presentation (deptrac : Presentation → Domain, Application).
 *
 * @implements ProcessorInterface<mixed, SynthesisResource>
 */
final class SynthesisStubProcessor implements ProcessorInterface
{
    /**
     * Génère une synthèse stub pour un article donné.
     *
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): SynthesisResource {
        $rawId = $uriVariables['id'] ?? '';
        $articleId = \is_string($rawId) ? $rawId : '';
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new SynthesisResource(
            id: Uuid::v4()->toRfc4122(),
            articleId: $articleId,
            content: 'BRIEFLY AI: Synthèse générée automatiquement pour l\'article ' . $articleId
                . '. [Sprint 1 placeholder — intégration Mistral prévue en US-010]',
            generatedAt: $now->format(\DateTimeInterface::ATOM),
            quotaUsed: 0,   // sera mis à jour par QuotaStateProcessor
            quotaRemaining: 0,
        );
    }
}
