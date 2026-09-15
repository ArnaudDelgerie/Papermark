<?php

declare(strict_types=1);

namespace App\Form;

use App\Ai\ProviderName;
use App\Entity\Provider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Settings page: an images folder (required, chosen via the hub's folder
 * picker), one ProviderType per provider (keyed by name), plus a single
 * radio group for the selected provider. Each radio is rendered inside its
 * provider block by the template (form.selected[name]).
 *
 * @extends AbstractType<array{providers: array<string, Provider>, selected: ?ProviderName, imageFolder: ?string}>
 */
final class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('imageFolder', TextType::class, [
                'label' => 'components.settings.image_folder.title',
                'required' => true,
                'attr' => ['readonly' => true],
            ])
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'components',
        ]);
    }
}
