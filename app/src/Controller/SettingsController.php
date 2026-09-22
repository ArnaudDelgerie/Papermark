<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\Ai\ApiKeyDeleteFailedException;
use App\Exception\Ai\ApiKeySaveFailedException;
use App\Exception\InvalidRequestException;
use App\Response\StateErrorResponse;
use App\Response\StateSuccessResponse;
use App\Service\Ai\ApiKeyResolver;
use App\Service\EditorState;
use App\Enum\ProviderName;
use App\Enum\Setting\AppLocale;
use App\Enum\Setting\ThemeMode;
use App\Form\SettingsType;
use App\Repository\ProviderRepository;
use App\Repository\SettingRepository;
use App\Interface\SettingStoreInterface;
use ArnaudDelgerie\TFSAppBundle\Bridge\Exception\BridgeException;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The settings live in a modal of the editor page (see EDITOR_SETTINGS.md).
 * GET /settings is the content of its frame; every change is then saved by
 * itself, through the routes below. The ones that can change the editor state
 * (`ai_enabled`) answer {state, action} like EditorController's, and the
 * state's refusal form when they refuse.
 */
final class SettingsController extends AbstractController
{
    private const TRANSLATION_DOMAIN = 'components';

    public function __construct(
        private readonly SecretStoreInterface $secretStore,
        private readonly ApiKeyResolver $apiKeyResolver,
        private readonly ProviderRepository $providers,
        private readonly SettingRepository $settings,
        private readonly SettingStoreInterface $settingStore,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly EditorState $editorState,
    ) {
    }

    /**
     * The content of the frame, and nothing else: it carries no `src`, or
     * Turbo would see a frame that references itself.
     */
    #[Route('/settings', name: 'app_settings', methods: ['GET'])]
    public function index(): Response
    {
        $form = $this->createSettingsForm();

        $hasKey = [];
        foreach (ProviderName::cases() as $name) {
            $hasKey[$name->value] = $this->apiKeyResolver->resolve($name) !== null;
        }

        return $this->render('settings/index.html.twig', [
            'form' => $form,
            'has_key' => $hasKey,
        ]);
    }

    /**
     * Saves the whole form: the provider models, the selected provider and
     * the default mode. A refused form answers its own refusal form (422),
     * with the field errors in `mappedErrors`: the client shows them itself.
     */
    #[Route('/settings', name: 'app_settings_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $form = $this->createSettingsForm();
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            throw new InvalidRequestException('request_failed');
        }

        if (!$form->isValid()) {
            // The submitted values are already in the entities: none may be flushed.
            $this->entityManager->clear();

            [$genericErrors, $mappedErrors] = $this->formErrors($form);

            return new StateErrorResponse($this->editorState, $genericErrors, $mappedErrors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $setting = $this->settings->getOrCreate();
        $providersByName = $this->providers->findAllByName();
        $selected = $form->get('selected')->getData();

        // Changing the default mode must not switch what the editor shows now.
        $this->editorState->keepMode();
        $setting->setSelectedProvider($selected !== null ? $providersByName[$selected->value] : null);
        $setting->setDefaultMode($form->get('defaultMode')->getData());
        $this->settings->update($setting);

        $this->editorState->refreshAiEnabled();

        return new StateSuccessResponse($this->editorState, []);
    }

    /**
     * Puts a provider's API key in the keyring. Its own action, not part of
     * the form: a key typed halfway must never be stored, and storing one
     * can turn the AI on.
     */
    #[Route('/settings/provider/{name}/key', name: 'app_settings_set_key', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function setKey(ProviderName $name, Request $request): JsonResponse
    {
        $key = $request->request->get('key');
        if (!\is_string($key) || trim($key) === '') {
            throw new InvalidRequestException('no_key');
        }

        try {
            $this->secretStore->set($name->value, trim($key));
        } catch (BridgeException) {
            throw new ApiKeySaveFailedException($name->value);
        }

        $this->editorState->refreshAiEnabled();

        return new StateSuccessResponse($this->editorState, ['name' => $name->value]);
    }

    /**
     * Removes a provider's API key from the keyring, and deselects the
     * provider if it was the selected one.
     */
    #[Route('/settings/provider/{name}/key', name: 'app_settings_delete_key', methods: ['DELETE'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function deleteKey(ProviderName $name, Request $request): JsonResponse
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

        return new StateSuccessResponse($this->editorState, ['name' => $name->value]);
    }

    /**
     * The theme button of the file bar. The page is already showing it: this
     * only remembers it, and it is not part of the editor state.
     */
    #[Route('/settings/theme', name: 'app_settings_theme', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function theme(Request $request): JsonResponse
    {
        $value = $request->request->get('theme');
        $theme = \is_string($value) ? ThemeMode::tryFrom($value) : null;
        if ($theme === null) {
            throw new InvalidRequestException('request_failed');
        }

        $setting = $this->settings->getOrCreate();
        $setting->setThemeMode($theme);
        $this->settings->update($setting);

        return new JsonResponse(['theme' => $theme->value]);
    }

    /**
     * The language button of the file bar: a native form post, then a full
     * reload on the home page, where the server renders everything translated.
     */
    #[Route('/settings/locale', name: 'app_settings_locale', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: '_token')]
    public function locale(Request $request): RedirectResponse
    {
        $value = $request->request->get('locale');
        $locale = \is_string($value) ? AppLocale::tryFrom($value) : null;
        if ($locale === null) {
            throw new InvalidRequestException('request_failed');
        }

        $setting = $this->settings->getOrCreate();
        $setting->setLocale($locale);
        $this->settings->update($setting);

        return $this->redirectToRoute('app_home');
    }

    /**
     * @return FormInterface<mixed>
     */
    private function createSettingsForm(): FormInterface
    {
        return $this->createForm(SettingsType::class, [
            'providers' => $this->providers->findAllByName(),
            'selected' => $this->settingStore->getSelectedProviderName(),
            'defaultMode' => $this->settingStore->getDefaultMode(),
        ], [
            // Stateless token, checked by the request's origin (config/packages/csrf.yaml).
            'csrf_token_id' => 'papermark_app',
        ]);
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
