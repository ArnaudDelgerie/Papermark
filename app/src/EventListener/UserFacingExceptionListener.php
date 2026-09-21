<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Editor\EditorState;
use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Answers a UserFacingExceptionInterface with the editor's `{ error, state }`
 * response. Runs between the framework ErrorListener's two steps (0 and
 * -128): after the first has wrapped the exception in an HttpException
 * carrying its #[WithHttpStatus] code, before the second renders an error
 * page.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
final class UserFacingExceptionListener
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly EditorState $editorState,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $exception = $throwable->getPrevious();
        if (!$throwable instanceof HttpExceptionInterface || !$exception instanceof UserFacingExceptionInterface) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => $this->translator->trans($exception->getTranslationKey(), domain: 'exceptions'),
            'state' => $this->editorState->toArray(),
        ], $throwable->getStatusCode(), $throwable->getHeaders()));
    }
}
