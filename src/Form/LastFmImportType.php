<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array<string, mixed>>
 */
class LastFmImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('lastfm_user', TextType::class, [
                'label' => 'Identifiant Last.fm',
                'constraints' => [new Assert\NotBlank()],
                'help' => 'Le username Last.fm dont l\'historique sera importé (doit être public). '
                    . 'Pré-rempli depuis LASTFM_USER si la variable est définie.',
            ])
            ->add('api_key', PasswordType::class, [
                'label' => 'API key Last.fm',
                'required' => false,
                'always_empty' => false,
                'help' => 'Optionnelle si LASTFM_API_KEY est défini dans l\'environnement. À créer sur last.fm/api/account/create.',
            ])
            ->add('date_min', DateType::class, [
                'label' => 'Date min (optionnel)',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
            ])
            ->add('date_max', DateType::class, [
                'label' => 'Date max (optionnel)',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
            ])
            ->add('tolerance', IntegerType::class, [
                'label' => 'Tolérance dedup (secondes)',
                'data' => 60,
                'attr' => ['min' => 0, 'max' => 3600],
                'help' => 'Un scrobble Last.fm n\'est pas réinséré s\'il existe déjà un scrobble Navidrome à ± cette durée.',
            ])
            ->add('max_scrobbles', IntegerType::class, [
                'label' => 'Limite de sécurité (max scrobbles)',
                'required' => false,
                'data' => 5000,
                'attr' => ['min' => 1, 'max' => 1000000],
                'help' => 'Stoppe après N scrobbles pour éviter les timeouts HTTP. Vider pour ne pas limiter (déconseillé via l\'UI).',
            ])
            ->add('dry_run', CheckboxType::class, [
                'label' => 'Dry-run (ne rien écrire dans Navidrome)',
                'required' => false,
                'data' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
