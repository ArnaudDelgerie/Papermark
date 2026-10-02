<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Provider;
use App\Enum\ProviderName;
use App\Enum\Setting\EditorMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Settings form, saved as a whole on every change: one ProviderType per
 * provider (keyed by name), plus a single radio group for the selected
 * provider. Each radio is rendered inside its provider block by the template
 * (form.selected[name]). Theme and language are not here: they have their own
 * buttons in the file bar. The two autosave switches (EDITOR_AUTOSAVE.md)
 * live with the default mode, in the Editor tab.
 *
 * @extends AbstractType<array{providers: array<string, Provider>, selected: ?ProviderName, defaultMode: EditorMode, autosave: bool, autosaveAfterAi: bool}>
 */
final class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('providers', CollectionType::class, [
                'entry_type' => ProviderType::class,
                'label' => false,
            ])
            ->add('selected', EnumType::class, [
                'class' => ProviderName::class,
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'choice_label' => static fn (ProviderName $name): string => 'components.settings.provider.' . $name->value,
                'choice_name' => static fn (ProviderName $name): string => $name->value,
            ])
            ->add('defaultMode', EnumType::class, [
                'class' => EditorMode::class,
                'expanded' => true,
                'choice_label' => static fn (EditorMode $mode): string => 'components.mode.' . $mode->value,
            ])
            ->add('autosave', CheckboxType::class, [
                'label' => 'components.settings.autosave',
                'required' => false,
            ])
            ->add('autosaveAfterAi', CheckboxType::class, [
                'label' => 'components.settings.autosave_after_ai',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'components',
        ]);
    }
}
