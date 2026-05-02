<?php

namespace App\Form;

use App\Entity\LastFmArtistAlias;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<LastFmArtistAlias|null>
 */
class LastFmArtistAliasType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var LastFmArtistAlias|null $alias */
        $alias = $options['alias'];
        $prefillSource = $options['prefill_source_artist'] !== ''
            ? $options['prefill_source_artist']
            : ($alias?->getSourceArtist() ?? '');

        $builder
            ->add('source_artist', TextType::class, [
                'label' => 'Artiste source (Last.fm)',
                'help' => 'Le nom tel qu\'envoyé par Last.fm (ex. « La Ruda Salska »). Comparé sans accents / casse / ponctuation.',
                'data' => $prefillSource,
                'constraints' => [new NotBlank()],
            ])
            ->add('target_artist', TextType::class, [
                'label' => 'Artiste cible (canonique côté Navidrome)',
                'help' => 'Le nom tel qu\'il apparaît dans votre lib Navidrome (ex. « La Ruda »). Une fois la cascade relancée, tous les scrobbles de l\'artiste source seront ré-essayés avec ce nom.',
                'data' => $alias?->getTargetArtist() ?? '',
                'constraints' => [new NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'alias' => null,
            'prefill_source_artist' => '',
        ]);
        $resolver->setAllowedTypes('alias', [LastFmArtistAlias::class, 'null']);
        $resolver->setAllowedTypes('prefill_source_artist', 'string');
    }
}
