<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Provider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One provider block of the settings page. The API key is not mapped: it goes
 * to the keyring, and an empty field keeps the stored key.
 *
 * @extends AbstractType<Provider>
 */
final class ProviderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('model', TextType::class, [
                'label' => 'components.settings.model_label',
                'required' => false,
            ])
            ->add('apiKey', PasswordType::class, [
                'label' => 'components.settings.api_key_label',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'placeholder' => 'components.settings.key_placeholder',
                    'autocomplete' => 'off',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Provider::class,
            'translation_domain' => 'components',
        ]);
    }
}
