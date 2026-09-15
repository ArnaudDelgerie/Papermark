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

    /** Matches the default Mercure JWT lifetime (Authorization::createCookie, 1 hour). */
    private const COOKIE_LIFETIME = 3600;

    /** Recreate the cookie when less than this many seconds remain. */
    private const COOKIE_RENEWAL_MARGIN = 1800;

    private const SESSION_COOKIE_EXPIRES_AT = '_ai_mercure_cookie_expires_at';

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly MessageBusInterface $bus,
        private readonly AiTopicResolver $topicResolver,
        private readonly AiAbortRegistry $abortRegistry,
    ) {
    }

    /**
     * Mints the subscriber cookie when it is expired or near expiry. The server
     * tracks the cookie's expiration in session so it decides, not the client:
     * the cookie is HttpOnly and limited to the hub path, so the front-end can't
     * read it. A generation never lasts 30 min, so the hub won't cut a running
     * stream when the cookie is fresh.
     */
    #[Route('/ai/subscribe', name: 'app_ai_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, Authorization $mercureAuthorization): Response
    {
        if (!$this->isCsrfTokenValidFor($request)) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $session = $request->getSession();
        $expiresAt = $session->get(self::SESSION_COOKIE_EXPIRES_AT);

        if (!\is_int($expiresAt) || time() >= $expiresAt - self::COOKIE_RENEWAL_MARGIN) {
            $mercureAuthorization->setCookie($request, [$this->topicResolver->resolve($request)]);
            $session->set(self::SESSION_COOKIE_EXPIRES_AT, time() + self::COOKIE_LIFETIME);
        }

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
