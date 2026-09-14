<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiAbortRegistry;
use App\Ai\AiInstructionMessage;
use App\Ai\AiTopicResolver;
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
        private readonly AiTopicResolver $topicResolver,
        private readonly AiAbortRegistry $abortRegistry,
    ) {
    }

    /**
     * Re-mints the subscriber cookie: the hub closes a subscription when its JWT
     * expires, so the editor renews it before a request once the cookie gets old.
     */
    #[Route('/ai/subscribe', name: 'app_ai_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, Authorization $mercureAuthorization): Response
    {
        if (!$this->isCsrfTokenValidFor($request)) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $mercureAuthorization->setCookie($request, [$this->topicResolver->resolve($request)]);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/ai/instruct', name: 'app_ai_instruct', methods: ['POST'])]
    public function instruct(Request $request): Response
    {
        if (!$this->isCsrfTokenValidFor($request)) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !$this->isRequestId($data['id'] ?? null)) {
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

        $this->bus->dispatch(new AiInstructionMessage(
            topic: $this->topicResolver->resolve($request),
            requestId: $data['id'],
            instruction: $instruction,
            document: $document,
            selection: $selection,
        ));

        return new Response(null, Response::HTTP_ACCEPTED);
    }

    #[Route('/ai/abort', name: 'app_ai_abort', methods: ['POST'])]
    public function abort(Request $request): Response
    {
        if (!$this->isCsrfTokenValidFor($request)) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !$this->isRequestId($data['id'] ?? null)) {
            return $this->errorResponse('invalid_body', Response::HTTP_BAD_REQUEST);
        }

        $this->abortRegistry->abort($data['id']);

        return new Response(null, Response::HTTP_NO_CONTENT);
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

    private function isCsrfTokenValidFor(Request $request): bool
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');

        return \is_string($csrfToken) && $this->csrfTokenManager->isTokenValid(new CsrfToken('ai', $csrfToken));
    }

    /**
     * @phpstan-assert-if-true non-empty-string $id
     */
    private function isRequestId(mixed $id): bool
    {
        return \is_string($id) && Uuid::isValid($id);
    }

    private function errorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN)],
            $status,
        );
    }
}
