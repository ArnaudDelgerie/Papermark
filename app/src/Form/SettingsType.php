<?php

declare(strict_types=1);

namespace App\Form;

use App\Ai\ProviderName;
use App\Editor\EditorMode;
use App\Entity\Provider;
use App\Locale\AppLocale;
use App\Theme\ThemeMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Settings page: one ProviderType per provider (keyed by name), plus a single
 * radio group for the selected provider. Each radio is rendered inside its
 * provider block by the template (form.selected[name]).
 *
 * @extends AbstractType<array{providers: array<string, Provider>, selected: ?ProviderName, defaultMode: EditorMode, themeMode: ThemeMode, locale: AppLocale}>
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
            ->add('themeMode', EnumType::class, [
                'class' => ThemeMode::class,
                'expanded' => true,
                'choice_label' => static fn (ThemeMode $mode): string => 'components.theme.' . $mode->value,
            ])
            ->add('locale', EnumType::class, [
                'class' => AppLocale::class,
                'expanded' => true,
                'choice_label' => static fn (AppLocale $locale): string => 'components.locale.' . $locale->value,
            ])
            ->add('save', SubmitType::class, [
                'label' => 'components.settings.save',
            ])
            ->add('saveAndClose', SubmitType::class, [
                'label' => 'components.settings.save_and_close',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'components',
        ]);
    }
}
