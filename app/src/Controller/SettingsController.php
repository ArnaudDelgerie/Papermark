<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Setting\SetKeyRequest;
use App\Dto\Setting\SetLocaleRequest;
use App\Dto\Setting\SetThemeRequest;
use App\Response\StateSuccessResponse;
use App\Service\Ai\ApiKeyResolver;
use App\Service\Editor\EditorState;
use App\Service\Setting\SettingsUpdater;
use App\Enum\ProviderName;
use App\Form\SettingsType;
use App\Repository\ProviderRepository;
use App\Interface\SettingStoreInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use ArnaudDelgerie\TFSAppBundle\HubContext\HubContextInterface;

/**
 * The settings live in a modal of the editor page (see EDITOR_SETTINGS.md).
 * GET /settings is the content of its frame; every change is then saved by
 * itself, through the routes below. The ones that can change the editor state
 * (`ai_enabled`) answer {state, action} like EditorController's, and the
 * state's refusal form when they refuse. Every write delegates to
 * SettingsUpdater; this class only builds and binds the form, and renders
 * the read side.
 */
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly ApiKeyResolver $apiKeyResolver,
        private readonly ProviderRepository $providers,
        private readonly SettingStoreInterface $settingStore,
        private readonly SettingsUpdater $settingsUpdater,
        private readonly EditorState $editorState,
        private readonly HubContextInterface $hubContext,
        private readonly SecretStoreInterface $secretStore,
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
            // HUB-06, lot 09: the hub's capabilities, probed one by one —
            // never "am I in the hub". The bridge probe is the secret store's
            // own availability: it is exactly what makes saving a key fail.
            'has_worker' => $this->hubContext->isAsyncWorker(),
            'bridge_available' => $this->secretStore->isAvailable(),
            'keyring_available' => $this->hubContext->isKeyringAvailable(),
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

        $this->settingsUpdater->save($form);

        return new StateSuccessResponse($this->editorState, []);
    }

    /**
     * Puts a provider's API key in the keyring.
     */
    #[Route('/settings/provider/{name}/key', name: 'app_settings_set_key', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function setKey(ProviderName $name, #[MapRequestPayload(mapWhenEmpty: true)] SetKeyRequest $payload): JsonResponse
    {
        $this->settingsUpdater->setKey($name, $payload->_password);

        return new StateSuccessResponse($this->editorState, ['name' => $name->value]);
    }

    /**
     * Removes a provider's API key from the keyring, and deselects the
     * provider if it was the selected one.
     */
    #[Route('/settings/provider/{name}/key', name: 'app_settings_delete_key', methods: ['DELETE'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function deleteKey(ProviderName $name): JsonResponse
    {
        $this->settingsUpdater->deleteKey($name);

        return new StateSuccessResponse($this->editorState, ['name' => $name->value]);
    }

    /**
     * The theme button of the file bar. The page is already showing it: this
     * only remembers it, and it is not part of the editor state.
     */
    #[Route('/settings/theme', name: 'app_settings_theme', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function theme(#[MapRequestPayload(mapWhenEmpty: true)] SetThemeRequest $payload): JsonResponse
    {
        \assert($payload->theme !== null);

        $this->settingsUpdater->setTheme($payload->theme);

        return new JsonResponse(['theme' => $payload->theme->value]);
    }

    /**
     * The language button of the file bar: a native form post, then a full
     * reload on the home page, where the server renders everything translated.
     */
    #[Route('/settings/locale', name: 'app_settings_locale', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: '_token')]
    public function locale(#[MapRequestPayload(mapWhenEmpty: true)] SetLocaleRequest $payload): RedirectResponse
    {
        \assert($payload->locale !== null);

        $this->settingsUpdater->setLocale($payload->locale);

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
            'autosave' => $this->settingStore->isAutosave(),
            'autosaveAfterAi' => $this->settingStore->isAutosaveAfterAi(),
        ], [
            // Stateless token, checked by the request's origin (config/packages/csrf.yaml).
            'csrf_token_id' => 'papermark_app',
        ]);
    }
}
