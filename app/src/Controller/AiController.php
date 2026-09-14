<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiInstructionMessage;
use App\Ai\ApiKeyResolver;
use App\Ai\ProviderName;
use ArnaudDelgerie\TFSAppBundle\StationContext\StationContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiController extends AbstractController
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.editor.error.';

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly MessageBusInterface $bus,
        private readonly StationContextInterface $stationContext,
        private readonly ApiKeyResolver $apiKeyResolver,
    ) {
    }

    #[Route('/ai/instruct', name: 'app_ai_instruct', methods: ['POST'])]
    public function instruct(Request $request, Authorization $mercureAuthorization): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('ai', $csrfToken))) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->errorResponse('invalid_body', Response::HTTP_BAD_REQUEST);
        }

        $instruction = $data['instruction'] ?? null;
        $document = $data['document'] ?? '';
        $selection = $data['selection'] ?? '';

        if (!\is_string($instruction) || $instruction === '') {
            return $this->errorResponse('no_instruction', Response::HTTP_BAD_REQUEST);
        }

        if (!\is_string($document)) {
            $document = '';
        }

        if (!\is_string($selection)) {
            $selection = '';
        }

        // Generate an unguessable per-session topic name.
        $topic = 'ai/' . Uuid::v4()->toRfc4122();

        // Mint a subscriber JWT for this topic so the browser's EventSource can connect.
        $mercureAuthorization->setCookie($request, [$topic]);

        $this->bus->dispatch(new AiInstructionMessage(
            topic: $topic,
            instruction: $instruction,
            document: $document,
            selection: $selection,
        ));

        return new JsonResponse(['topic' => $topic]);
    }

    #[Route('/ai/config', name: 'app_ai_config', methods: ['GET'])]
    public function config(): JsonResponse
    {
        $providers = [];
        foreach (ProviderName::cases() as $provider) {
            $providers[$provider->value] = [
                'has_key' => $this->apiKeyResolver->resolve($provider) !== null,
            ];
        }

        return new JsonResponse([
            'enabled' => $this->stationContext->isAsyncWorker(),
            'providers' => $providers,
        ]);
    }

    private function errorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN)],
            $status,
        );
    }
}
