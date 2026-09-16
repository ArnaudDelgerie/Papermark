<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\ApiKeyResolver;
use App\Ai\ProviderName;
use App\Form\SettingsType;
use App\Repository\ProviderRepository;
use App\Repository\SettingRepository;
use ArnaudDelgerie\TFSAppBundle\Bridge\Exception\BridgeException;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SettingsController extends AbstractController
{
    private const TRANSLATION_DOMAIN = 'components';

    public function __construct(
        private readonly SecretStoreInterface $secretStore,
        private readonly ApiKeyResolver $apiKeyResolver,
        private readonly ProviderRepository $providers,
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/settings', name: 'app_settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $setting = $this->settings->getOrCreate();
        $providersByName = $this->providers->findAllByName();

        $form = $this->createForm(SettingsType::class, [
            'providers' => $providersByName,
            'selected' => $setting->getSelectedProvider()?->getName(),
            'defaultMode' => $setting->getDefaultMode(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selected = $form->get('selected')->getData();

            try {
                foreach ($form->get('providers') as $providerForm) {
                    $provider = $providerForm->getData();

                    $apiKey = $providerForm->get('apiKey')->getData();
                    if (\is_string($apiKey) && $apiKey !== '') {
                        $this->secretStore->set($provider->getName()->value, $apiKey);
                    }
                }

                $setting->setSelectedProvider($selected !== null ? $providersByName[$selected->value] : null);
                $setting->setDefaultMode($form->get('defaultMode')->getData());

                $this->entityManager->flush();

                $this->addFlash('success', $this->translator->trans('components.settings.saved', [], self::TRANSLATION_DOMAIN));

                $saveAndClose = $form->get('saveAndClose');
                $closeAfterSave = $saveAndClose instanceof ClickableInterface && $saveAndClose->isClicked();

                return $this->redirectToRoute($closeAfterSave ? 'app_home' : 'app_settings');
            } catch (BridgeException) {
                $form->addError(new FormError(
                    $this->translator->trans('components.editor.error.save_failed', [], 'components'),
                ));
            }
        }

        $hasKey = [];
        foreach (ProviderName::cases() as $name) {
            $hasKey[$name->value] = $this->apiKeyResolver->resolve($name) !== null;
        }

        return $this->render('settings/index.html.twig', [
            'form' => $form,
            'has_key' => $hasKey,
            'delete_key_csrf_token' => $this->csrfTokenManager->getToken('delete_key')->getValue(),
        ]);
    }

    /**
     * Removes a provider's stored API key from the keyring. Fetch-based, like
     * the rest of this app's write actions (see FileController) — the CSRF
     * token travels in a header, not a form field.
     */
    #[Route('/settings/provider/{name}/key', name: 'app_settings_delete_key', methods: ['POST'])]
    public function deleteKey(ProviderName $name, Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('delete_key', $csrfToken))) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        try {
            $this->secretStore->delete($name->value);
        } catch (BridgeException) {
            return $this->errorResponse('key_delete_failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $setting = $this->settings->getOrCreate();
        if ($setting->getSelectedProvider()?->getName() === $name) {
            $setting->setSelectedProvider(null);
            $this->entityManager->flush();
        }

        // No immediate toast: the front end reloads the page to reflect the
        // removed key and possible deselection, and the flash renders then.
        $this->addFlash('success', $this->translator->trans('components.settings.key_deleted', [], self::TRANSLATION_DOMAIN));

        return new JsonResponse(['ok' => true]);
    }

    private function errorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans('components.editor.error.' . $key, [], self::TRANSLATION_DOMAIN)],
            $status,
        );
    }
}
