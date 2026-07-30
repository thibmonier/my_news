<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO Presentation — Données du formulaire de profil utilisateur.
 *
 * Découple le formulaire de l'entité Doctrine (anti-corruption layer Presentation).
 * Les validations Symfony sont portées par ce DTO.
 *
 * Couche Presentation — dépend de Domain + Symfony Validator uniquement.
 * L'unicité de l'email est vérifiée manuellement dans ProfileController
 * via UserRepositoryInterface (respect deptrac Presentation:[Domain, Application]).
 */
final class ProfileFormData
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le nom complet est obligatoire.')]
        #[Assert\Length(
            min: 1,
            max: 255,
            maxMessage: 'Le nom complet ne peut pas dépasser 255 caractères.',
        )]
        public string $fullName = '',

        #[Assert\Length(
            max: 280,
            maxMessage: 'La bio ne peut pas dépasser 280 caractères.',
        )]
        public ?string $bio = null,

        #[Assert\NotBlank(message: "L'adresse email est obligatoire.")]
        #[Assert\Email(
            mode: 'html5',
            message: "L'adresse email « {{ value }} » n'est pas valide.",
        )]
        #[Assert\Length(
            max: 255,
            maxMessage: "L'adresse email ne peut pas dépasser 255 caractères.",
        )]
        public string $email = '',
    ) {
    }
}
