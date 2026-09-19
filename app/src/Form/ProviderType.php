<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Provider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One provider block of the settings form: the model. The API key is not part
 * of the form (it goes to the keyring through its own actions, see
 * SettingsController::setKey()).
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
