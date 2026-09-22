<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Editor\EditorState;
use App\Exception\FormRefusedException;
use App\Interface\UserFacingExceptionInterface;
use App\Response\StateErrorResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one place an error answer is built: every refusal a user should read
 * comes out as a StateErrorResponse, whatever raised it.
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

        $event->setResponse(new StateErrorResponse(
            $this->editorState,
            [$this->translator->trans($exception->getTranslationKey(), domain: 'exceptions')],
            [],
            $throwable->getStatusCode(),
            $throwable->getHeaders(),
        ));
    }

    /**
     * A body #[MapRequestPayload] refused: each violation stays bound to its
     * field, so the caller can show it in place instead of as a toast.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
    public function onValidationFailed(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $exception = $throwable->getPrevious();
        if (!$throwable instanceof HttpExceptionInterface || !$exception instanceof ValidationFailedException) {
            return;
        }

        $mappedErrors = [];
        foreach ($exception->getViolations() as $violation) {
            $mappedErrors[] = ['field' => $violation->getPropertyPath(), 'message' => $violation->getMessage()];
        }

        $event->setResponse(new StateErrorResponse(
            $this->editorState,
            [],
            $mappedErrors,
            $throwable->getStatusCode(),
            $throwable->getHeaders(),
        ));
    }

    /**
     * A server-rendered form refused a submission: its messages are already
     * translated, unlike onUserFacingException's single key.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
    public function onFormRefused(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $exception = $throwable->getPrevious();
        if (!$throwable instanceof HttpExceptionInterface || !$exception instanceof FormRefusedException) {
            return;
        }

        $event->setResponse(new StateErrorResponse(
            $this->editorState,
            $exception->genericErrors,
            $exception->mappedErrors,
            $throwable->getStatusCode(),
            $throwable->getHeaders(),
        ));
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

        $event->setResponse(new StateErrorResponse(
            $this->editorState,
            [$this->translator->trans('exceptions.security.csrf_invalid', domain: 'exceptions')],
            [],
            Response::HTTP_FORBIDDEN,
        ));
    }
}
