<?php

namespace App\Form;

use App\Entity\PlaylistDefinition;
use App\Generator\GeneratorRegistry;
use App\Generator\ParameterDefinition;
use App\Generator\PlaylistGeneratorInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PlaylistDefinition>
 */
class PlaylistDefinitionType extends AbstractType
{
    public function __construct(private readonly GeneratorRegistry $registry)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de la définition',
                'help' => 'Identifiant interne (unique). Différent du nom final de la playlist Navidrome.',
            ])
            ->add('generatorKey', ChoiceType::class, [
                'label' => 'Type de playlist (générateur)',
                'choices' => $this->registry->choices(),
                'placeholder' => '— choisir —',
            ])
            ->add('limitOverride', IntegerType::class, [
                'label' => 'Nombre de morceaux',
                'required' => false,
                'help' => 'Laisser vide pour utiliser la valeur par défaut globale.',
                'attr' => ['min' => 1, 'max' => 1000],
            ])
            ->add('playlistNameTemplate', TextType::class, [
                'label' => 'Modèle du nom de playlist Navidrome',
                'required' => false,
                'help' => 'Variables : {date}, {month}, {year}, {label}, {name}, {preset}, {param:xxx}.',
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'Activée',
                'required' => false,
            ])
            ->add('replaceExisting', CheckboxType::class, [
                'label' => 'Remplacer la playlist Navidrome existante du même nom',
                'required' => false,
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            /** @var PlaylistDefinition $data */
            $data = $event->getData();
            $key = $data->getGeneratorKey();
            if ($key !== '' && $this->registry->has($key)) {
                $this->addParameterFields($event->getForm(), $this->registry->get($key), $data->getParameters());
            }
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $key = $data['generatorKey'] ?? '';
            if (is_string($key) && $key !== '' && $this->registry->has($key)) {
                $this->addParameterFields($event->getForm(), $this->registry->get($key), []);
            }
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            /** @var PlaylistDefinition $def */
            $def = $event->getData();
            $form = $event->getForm();

            // Collect generator parameters from dynamic fields into the entity.
            if ($form->has('_params')) {
                $paramsForm = $form->get('_params');
                $values = [];
                foreach ($paramsForm->all() as $name => $child) {
                    $values[$name] = $child->getData();
                }
                $def->setParameters($values);
            }
        });
    }

    /**
     * @param FormInterface<PlaylistDefinition> $form
     * @param array<string, mixed>              $currentValues
     */
    private function addParameterFields(FormInterface $form, PlaylistGeneratorInterface $generator, array $currentValues): void
    {
        $schema = $generator->getParameterSchema();
        if ($schema === []) {
            return;
        }

        $sub = $form->getConfig()->getFormFactory()->createNamedBuilder('_params', \Symfony\Component\Form\Extension\Core\Type\FormType::class, null, [
            'auto_initialize' => false,
            'mapped' => false,
            'inherit_data' => false,
            'label' => 'Paramètres du générateur',
        ]);

        foreach ($schema as $param) {
            $opts = [
                'label' => $param->label,
                'required' => $param->required,
                'help' => $param->help,
                'data' => $currentValues[$param->name] ?? $param->default,
            ];

            switch ($param->type) {
                case ParameterDefinition::TYPE_INT:
                    if ($param->min !== null) {
                        $opts['attr']['min'] = $param->min;
                    }
                    if ($param->max !== null) {
                        $opts['attr']['max'] = $param->max;
                    }
                    $sub->add($param->name, IntegerType::class, $opts);
                    break;
                case ParameterDefinition::TYPE_BOOL:
                    $sub->add($param->name, CheckboxType::class, $opts);
                    break;
                case ParameterDefinition::TYPE_CHOICE:
                    $opts['choices'] = array_flip($param->choices);
                    $sub->add($param->name, ChoiceType::class, $opts);
                    break;
                default:
                    $sub->add($param->name, TextType::class, $opts);
            }
        }

        $form->add($sub->getForm());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlaylistDefinition::class,
        ]);
    }
}
