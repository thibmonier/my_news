<?php

declare(strict_types=1);

namespace App\Presentation\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire Symfony — Création et édition d'une Source RSS/Atom.
 *
 * Champs :
 *  - name (TextType) : nom de la source, NotBlank
 *  - url (UrlType) : URL du flux, HTTPS, contrainte SSRF (SsrfSafeUrlConstraint)
 *  - feedType (ChoiceType) : rss | atom
 *  - fetchIntervalMinutes (IntegerType) : intervalle de récupération (min 5)
 *
 * CSRF activé par défaut via Symfony Form (csrf_protection: true dans framework.yaml).
 * Couche Presentation — Deptrac: Presentation:[Domain, Application].
 */
final class SourceType extends AbstractType
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de la source',
                'attr' => [
                    'placeholder' => 'ex : MIT Tech Review',
                    'class' => 'input input-bordered w-full',
                ],
            ])
            ->add('url', UrlType::class, [
                'label' => 'URL du flux (HTTPS uniquement)',
                'attr' => [
                    'placeholder' => 'https://www.example.com/feed/',
                    'class' => 'input input-bordered w-full',
                ],
                'default_protocol' => null,
            ])
            ->add('feedType', ChoiceType::class, [
                'label' => 'Type de flux',
                'choices' => [
                    'RSS' => 'rss',
                    'Atom' => 'atom',
                ],
                'attr' => ['class' => 'select select-bordered w-full'],
            ])
            ->add('fetchIntervalMinutes', IntegerType::class, [
                'label' => 'Intervalle de récupération (minutes)',
                'attr' => [
                    'min' => 5,
                    'max' => 10080,
                    'class' => 'input input-bordered w-full',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SourceFormData::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'source_form',
        ]);
    }
}
