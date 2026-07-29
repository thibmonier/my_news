<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use App\Domain\Feed\Source;
use App\Presentation\Validator\SsrfSafeUrl;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO — Données de formulaire pour la création/édition d'une Source.
 *
 * Sépare la représentation formulaire de l'entité Domain (SRP).
 * Les contraintes de validation Symfony sont portées par ce DTO.
 *
 * SSRF protection : #[SsrfSafeUrl] sur le champ url bloque toute URL
 * pointant vers une ressource réseau interne (RFC-1918, loopback, link-local).
 */
final class SourceFormData
{
    #[Assert\NotBlank(message: 'Le nom de la source est obligatoire.')]
    #[Assert\Length(max: 255, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'L\'URL du flux est obligatoire.')]
    #[Assert\Length(max: 2048, maxMessage: 'L\'URL ne peut pas dépasser {{ limit }} caractères.')]
    #[SsrfSafeUrl]
    public string $url = '';

    public string $feedType = 'rss';

    #[Assert\GreaterThanOrEqual(value: 5, message: 'L\'intervalle de récupération doit être d\'au moins {{ compared_value }} minutes.')]
    #[Assert\LessThanOrEqual(value: 10080, message: 'L\'intervalle ne peut pas dépasser {{ compared_value }} minutes (7 jours).')]
    public int $fetchIntervalMinutes = 30;

    /**
     * Construit un DTO à partir d'une entité Domain (pour les formulaires d'édition).
     */
    public static function fromSource(Source $source): self
    {
        $dto = new self();
        $dto->name = $source->getName();
        $dto->url = $source->getUrl();
        $dto->feedType = $source->getFeedType()->value;
        $dto->fetchIntervalMinutes = $source->getFetchIntervalMinutes();

        return $dto;
    }
}
