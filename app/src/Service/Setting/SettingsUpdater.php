<?php

declare(strict_types=1);

namespace App\Service\Setting;

use App\Enum\ProviderName;
use App\Enum\Setting\AppLocale;
use App\Enum\Setting\ThemeMode;
use App\Exception\Ai\ApiKeyDeleteFailedException;
use App\Exception\Ai\ApiKeySaveFailedException;
use App\Exception\FormRefusedException;
use App\Repository\ProviderRepository;
use App\Repository\SettingRepository;
use App\Service\Editor\EditorState;
use ArnaudDelgerie\TFSAppBundle\Bridge\Exception\BridgeException;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The five writes of the settings modal (lot 08-settings-controller.md).
 * `save` refuses a non-submitted or invalid form with FormRefusedException,
 * whose messages are already translated: the label prefixing a field's
 * message is this class's own, `components.settings.error_line`.
 */
final class SettingsUpdater
{
    private const TRANSLATION_DOMAIN = 'components';

    public function __construct(
        private readonly SecretStoreInterface $secretStore,
        private readonly ProviderRepository $providers,
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly EditorState $editorState,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @throws FormRefusedException the form was not submitted, or refused it
     */
    public function save(FormInterface $form): void
    {
        if (!$form->isSubmitted()) {
            throw new FormRefusedException([$this->translator->trans('exceptions.form.not_submitted', domain: 'exceptions')], []);
        }

        if (!$form->isValid()) {
            // The submitted values are already in the entities: none may be flushed.
            $this->entityManager->clear();

            [$genericErrors, $mappedErrors] = $this->formErrors($form);

            throw new FormRefusedException($genericErrors, $mappedErrors);
        }

        $setting = $this->settings->getOrCreate();
        $providersByName = $this->providers->findAllByName();
        $selected = $form->get('selected')->getData();

        // Changing the default mode must not switch what the editor shows now.
        $this->editorState->keepMode();
        $setting->setSelectedProvider($selected !== null ? $providersByName[$selected->value] : null);
        $setting->setDefaultMode($form->get('defaultMode')->getData());
        $setting->setAutosave($form->get('autosave')->getData());
        $setting->setAutosaveAfterAi($form->get('autosaveAfterAi')->getData());
        $this->settings->update($setting);

        $this->editorState->refreshAiEnabled();
    }

    /**
     * Its own operation, not part of the form: a key typed halfway must
     * never be stored, and storing one can turn the AI on.
     *
     * @throws ApiKeySaveFailedException
     */
    public function setKey(ProviderName $name, string $key): void
    {
        try {
            $this->secretStore->set($name->value, trim($key));
        } catch (BridgeException) {
            throw new ApiKeySaveFailedException($name->value);
        }

        $this->editorState->refreshAiEnabled();
    }

    /**
     * @throws ApiKeyDeleteFailedException
     */
    public function deleteKey(ProviderName $name): void
    {
        try {
            $this->secretStore->delete($name->value);
        } catch (BridgeException) {
            throw new ApiKeyDeleteFailedException($name->value);
        }

        $setting = $this->settings->getOrCreate();
        if ($setting->getSelectedProvider()?->getName() === $name) {
            $setting->setSelectedProvider(null);
            $this->settings->update($setting);
        }

        $this->editorState->refreshAiEnabled();
    }

    public function setTheme(ThemeMode $theme): void
    {
        $setting = $this->settings->getOrCreate();
        $setting->setThemeMode($theme);
        $this->settings->update($setting);
    }

    public function setLocale(AppLocale $locale): void
    {
        $setting = $this->settings->getOrCreate();
        $setting->setLocale($locale);
        $this->settings->update($setting);
    }

    /**
     * A form-level error (no origin, or the form itself) is generic; a
     * field's error is mapped, named after the field it came from.
     *
     * @param FormInterface<mixed> $form
     *
     * @return array{0: list<string>, 1: list<array{field: string, message: string}>}
     */
    private function formErrors(FormInterface $form): array
    {
        $genericErrors = [];
        $mappedErrors = [];
        foreach ($form->getErrors(true) as $error) {
            $origin = $error->getOrigin();
            if ($origin === null || $origin === $form) {
                $genericErrors[] = $error->getMessage();

                continue;
            }

            $label = $this->fieldLabel($origin);
            $mappedErrors[] = [
                'field' => $origin->createView()->vars['full_name'] ?? '',
                'message' => $label !== null
                    ? $this->trans('components.settings.error_line', ['{field}' => $label, '{message}' => $error->getMessage()])
                    : $error->getMessage(),
            ];
        }

        return [$genericErrors, $mappedErrors];
    }

    /**
     * What the user sees for a field, so an error can name it.
     *
     * @param FormInterface<mixed> $field
     */
    private function fieldLabel(FormInterface $field): ?string
    {
        $providerName = $field->getParent() !== null ? ProviderName::tryFrom($field->getParent()->getName()) : null;
        if ($field->getName() === 'model' && $providerName !== null) {
            return $this->trans('components.settings.provider.' . $providerName->value)
                . ' · ' . $this->trans('components.settings.model_label');
        }

        return match ($field->getName()) {
            'selected' => $this->trans('components.settings.tab_providers'),
            'defaultMode' => $this->trans('components.settings.mode_title'),
            default => null,
        };
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, self::TRANSLATION_DOMAIN);
    }
}
