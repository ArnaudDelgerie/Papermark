<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Ai\AiAbortRegistry;
use App\Dto\Ai\AiAbortRequest;
use App\Message\AiInstructionMessage;
use App\Dto\Ai\AiInstructRequest;
use App\Service\Ai\AiTopicResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * An invalid body gets Symfony's default 422: the editor never sends one, so the
 * browser shows a generic message.
 */
final class AiController extends AbstractController
{
    /** Matches the default Mercure JWT lifetime (Authorization::createCookie, 1 hour). */
    private const COOKIE_LIFETIME = 3600;

    /** Recreate the cookie when less than this many seconds remain. */
    private const COOKIE_RENEWAL_MARGIN = 1800;

    private const SESSION_COOKIE_EXPIRES_AT = '_ai_mercure_cookie_expires_at';

    public function __construct(
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
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function subscribe(Request $request, Authorization $mercureAuthorization): Response
    {
        $session = $request->getSession();
        $expiresAt = $session->get(self::SESSION_COOKIE_EXPIRES_AT);

        if (!\is_int($expiresAt) || time() >= $expiresAt - self::COOKIE_RENEWAL_MARGIN) {
            $mercureAuthorization->setCookie($request, [$this->topicResolver->resolve($request)]);
            $session->set(self::SESSION_COOKIE_EXPIRES_AT, time() + self::COOKIE_LIFETIME);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/ai/instruct', name: 'app_ai_instruct', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function instruct(Request $request, #[MapRequestPayload] AiInstructRequest $payload): Response
    {
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
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function abort(Request $request, #[MapRequestPayload] AiAbortRequest $payload): Response
    {
        $this->abortRegistry->abort($payload->id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
