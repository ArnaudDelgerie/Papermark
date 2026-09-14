<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiAbortRegistry;
use App\Ai\AiAbortRequest;
use App\Ai\AiInstructionMessage;
use App\Ai\AiInstructRequest;
use App\Ai\AiTopicResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An invalid body gets Symfony's default 422: the editor never sends one, so the
 * browser shows a generic message.
 */
final class AiController extends AbstractController
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.editor.error.';

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly MessageBusInterface $bus,
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
    public function instruct(Request $request, #[MapRequestPayload] AiInstructRequest $payload): Response
    {
        if (!$this->isCsrfTokenValidFor($request)) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $this->bus->dispatch(new AiInstructionMessage(
            topic: $this->topicResolver->resolve($request),
            requestId: $payload->id,
            instruction: $payload->instruction,
            document: $payload->document,
            selection: $payload->selection,
        ));

        return new Response(null, Response::HTTP_ACCEPTED);
    }

    #[Route('/ai/abort', name: 'app_ai_abort', methods: ['POST'])]
    public function abort(Request $request, #[MapRequestPayload] AiAbortRequest $payload): Response
    {
        if (!$this->isCsrfTokenValidFor($request)) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $this->abortRegistry->abort($payload->id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function isCsrfTokenValidFor(Request $request): bool
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');

        return \is_string($csrfToken) && $this->csrfTokenManager->isTokenValid(new CsrfToken('ai', $csrfToken));
    }

    private function errorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN)],
            $status,
        );
    }
}
