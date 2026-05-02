<?php

namespace App\Form;

use App\Entity\LastFmAlias;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<LastFmAlias|null>
 */
class LastFmAliasType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var LastFmAlias|null $alias */
        $alias = $options['alias'];
        $isSkip = $alias !== null && $alias->isSkip();
        $prefillArtist = $options['prefill_source_artist'] !== ''
            ? $options['prefill_source_artist']
            : ($alias?->getSourceArtist() ?? '');
        $prefillTitle = $options['prefill_source_title'] !== ''
            ? $options['prefill_source_title']
            : ($alias?->getSourceTitle() ?? '');

        $builder
            ->add('source_artist', TextType::class, [
                'label' => 'Artiste (Last.fm)',
                'help' => 'Tel qu\'écrit par Last.fm. Comparé sans accents / casse / ponctuation.',
                'data' => $prefillArtist,
                'constraints' => [new NotBlank()],
            ])
            ->add('source_title', TextType::class, [
                'label' => 'Titre (Last.fm)',
                'data' => $prefillTitle,
                'constraints' => [new NotBlank()],
            ])
            ->add('skip', CheckboxType::class, [
                'label' => 'Ignorer ce scrobble (au lieu de le mapper)',
                'help' => 'Cochez pour que ces scrobbles soient comptés en « Ignorés » plutôt qu\'écrits dans la lib (utile pour les podcasts ou le bruit).',
                'required' => false,
                'data' => $isSkip,
            ])
            ->add('target_media_file_id', TextType::class, [
                'label' => 'ID du media_file Navidrome',
                'help' => 'Cherchez le morceau dans Navidrome ; l\'ID figure dans l\'URL (ex. /app/#/track/abc-123-def → abc-123-def). Laissez vide si vous cochez « Ignorer ».',
                'data' => $alias !== null && !$alias->isSkip() ? $alias->getTargetMediaFileId() : '',
                'required' => false,
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $skip = (bool) $form->get('skip')->getData();
            $target = trim((string) $form->get('target_media_file_id')->getData());
            if (!$skip && $target === '') {
                $form->get('target_media_file_id')->addError(
                    new FormError('Renseignez un ID de media_file ou cochez « Ignorer ce scrobble ».'),
                );
            }
            if ($skip && $target !== '') {
                $form->get('target_media_file_id')->addError(
                    new FormError('Décochez « Ignorer » pour mapper vers un media_file.'),
                );
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'alias' => null,
            'prefill_source_artist' => '',
            'prefill_source_title' => '',
        ]);
        $resolver->setAllowedTypes('alias', [LastFmAlias::class, 'null']);
        $resolver->setAllowedTypes('prefill_source_artist', 'string');
        $resolver->setAllowedTypes('prefill_source_title', 'string');
    }
}
