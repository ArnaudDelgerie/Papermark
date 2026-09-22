<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\EditorState;
use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Answers the refusals the user should read with the editor's
 * `{ error, state }` response.
 */
final class UserFacingExceptionListener
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly EditorState $editorState,
    ) {
    }

    /**
     * Runs between the framework ErrorListener's two steps (0 and -128):
     * after the first has wrapped the exception in an HttpException carrying
     * its #[WithHttpStatus] code, before the second renders an error page.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
    public function onUserFacingException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $exception = $throwable->getPrevious();
        if (!$throwable instanceof HttpExceptionInterface || !$exception instanceof UserFacingExceptionInterface) {
            return;
        }

        $event->setResponse($this->response($exception->getTranslationKey(), $throwable->getStatusCode(), $throwable->getHeaders()));
    }

    /**
     * The refusal of #[IsCsrfTokenValid]. Runs before the firewall (1), which
     * would otherwise treat it as an authentication failure.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 2)]
    public function onInvalidCsrfToken(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof InvalidCsrfTokenException) {
            return;
        }

        $event->setResponse($this->response('exceptions.security.csrf_invalid', Response::HTTP_FORBIDDEN));
    }

    /**
     * @param array<string, string> $headers
     */
    private function response(string $translationKey, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'error' => $this->translator->trans($translationKey, domain: 'exceptions'),
            'state' => $this->editorState->toArray(),
        ], $status, $headers);
    }
}
