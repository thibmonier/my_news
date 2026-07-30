<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire Symfony — Édition du profil utilisateur (US-032).
 *
 * Champs :
 *  - fullName   (TextType)     : NotBlank, Length(max:255)
 *  - bio        (TextareaType) : Length(max:280), nullable
 *  - email      (EmailType)    : NotBlank, Email(html5), Length(max:255)
 *
 * CSRF activé par défaut (Symfony Form — framework.yaml csrf_protection: true).
 * L'unicité de l'email est validée dans ProfileController (deptrac compliance).
 *
 * Stimulus bio-counter : le textarea bio porte `data-controller` + `data-bio-counter-max-value`.
 *
 * Couche Presentation — Deptrac: Presentation:[Domain, Application].
 */
final class ProfileFormType extends AbstractType
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Nom complet',
                'attr' => [
                    'autocomplete' => 'name',
                    'placeholder' => 'Dr. Priya Kapoor',
                    'class' => 'input',
                ],
            ])
            ->add('bio', TextareaType::class, [
                'label' => 'Bio professionnelle',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Décrivez votre activité en 280 caractères maximum…',
                    'rows' => 4,
                    'class' => 'textarea',
                    'data-controller' => 'bio-counter',
                    'data-bio-counter-max-value' => '280',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse email',
                'attr' => [
                    'autocomplete' => 'email',
                    'class' => 'input',
                ],
                'help' => 'Un email de confirmation sera envoyé en cas de modification.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileFormData::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'profile_edit',
        ]);
    }
}
