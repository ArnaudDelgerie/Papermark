<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\ApiKeyResolver;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SettingsController extends AbstractController
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.settings.';
    private const PROVIDERS = ['openai', 'anthropic', 'mistral'];

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly SecretStoreInterface $secretStore,
        private readonly ApiKeyResolver $apiKeyResolver,
    ) {
    }

    #[Route('/settings', name: 'app_settings', methods: ['GET'])]
    public function index(): Response
    {
        $bridgeAvailable = $this->secretStore->isAvailable();

        $providers = [];
        foreach (self::PROVIDERS as $provider) {
            $providers[$provider] = [
                'has_key' => $this->apiKeyResolver->resolve($provider) !== null,
                'label' => $this->trans("provider.{$provider}"),
            ];
        }

        $selectedProvider = $this->apiKeyResolver->resolveProvider();

        return $this->render('settings/index.html.twig', [
            'bridge_available' => $bridgeAvailable,
            'providers' => $providers,
            'selected_provider' => $selectedProvider,
            'settings_csrf_token' => $this->csrfTokenManager->getToken('settings')->getValue(),
        ]);
    }

    #[Route('/settings/ai', name: 'app_settings_ai', methods: ['POST'])]
    public function saveAi(Request $request): Response
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('settings', $csrfToken))) {
            return $this->json(['error' => $this->transError('invalid_csrf')], Response::HTTP_FORBIDDEN);
        }

        if (!$this->secretStore->isAvailable()) {
            return $this->json(['error' => $this->transError('bridge_unavailable')], Response::HTTP_BAD_REQUEST);
        }

        $provider = $request->request->get('provider');
        if (!\is_string($provider) || !\in_array($provider, self::PROVIDERS, true)) {
            return $this->json(['error' => $this->transError('invalid_provider')], Response::HTTP_BAD_REQUEST);
        }

        // Save the selected provider.
        try {
            $this->secretStore->set('ai_provider', $provider);
        } catch (\Throwable) {
            return $this->json(['error' => $this->transError('save_failed')], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Save API keys for each provider (only non-empty values are stored).
        foreach (self::PROVIDERS as $name) {
            $key = $request->request->get("key_{$name}");
            if (\is_string($key) && $key !== '') {
                try {
                    $this->secretStore->set($name, $key);
                } catch (\Throwable) {
                    return $this->json(['error' => $this->transError('save_failed')], Response::HTTP_INTERNAL_SERVER_ERROR);
                }
            }
        }

        $this->addFlash('success', $this->trans('saved'));

        return $this->redirectToRoute('app_settings');
    }

    private function trans(string $key): string
    {
        return $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN);
    }

    private function transError(string $key): string
    {
        return $this->translator->trans('components.editor.error.' . $key, [], self::TRANSLATION_DOMAIN);
    }
}
