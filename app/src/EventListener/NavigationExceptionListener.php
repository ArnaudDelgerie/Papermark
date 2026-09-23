<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A page navigation (the language form post, the webview's own loads) must
 * never end on a raw error page or a JSON body: the webview has no address
 * bar nor back button to leave it with. Any exception on such a request
 * becomes an error flash — rendered as a toast by base.html.twig — and a
 * redirect to the home page (SET-03, lot 09).
 *
 * The fetches of utils/http.ts keep their JSON answers: they are never
 * navigations, see #isNavigation().
 */
final class NavigationExceptionListener
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Runs before UserFacingExceptionListener's CSRF handler (2) and the
     * firewall (1), and stops the propagation: a navigation's answer is the
     * redirect, whatever the lower-priority JSON builders would say.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 4)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isNavigation($request)) {
            return;
        }

        // No loop: the home page resolves to the editor page, so a failure
        // of either is the app being down — the error page says it, a
        // redirect would only bounce between the two forever.
        if (\in_array($request->attributes->get('_route'), ['app_home', 'app_editor'], true)) {
            return;
        }

        // The flashes bag, without depending on the concrete session class:
        // getBag() is the interface's own access.
        $bag = ($request->hasSession() ? $request->getSession() : null)?->getBag('flashes');
        if ($bag instanceof FlashBagInterface) {
            $bag->add('error', $this->message($event->getThrowable()));
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_home')));
        $event->stopPropagation();
    }

    /**
     * A navigation carries `Sec-Fetch-Mode: navigate`. WebKitGTK sends no
     * Sec-Fetch-Mode header at all: there, the criterion is the absence of
     * `X-CSRF-TOKEN`, which every fetch of utils/http.ts carries (tranché à
     * l'implémentation, lot 09).
     */
    private function isNavigation(Request $request): bool
    {
        $mode = $request->headers->get('sec-fetch-mode');

        return $mode === 'navigate' || ($mode === null && !$request->headers->has('x-csrf-token'));
    }

    /**
     * The exception's own message when it has one for the user, else a
     * generic one. Unwrapped and HttpException-wrapped alike: this runs
     * before the framework's ErrorListener does the wrapping.
     */
    private function message(\Throwable $throwable): string
    {
        $exception = $throwable->getPrevious() ?? $throwable;
        if ($exception instanceof UserFacingExceptionInterface) {
            return $this->translator->trans($exception->getTranslationKey(), domain: 'exceptions');
        }

        foreach ($exception instanceof ValidationFailedException ? $exception->getViolations() : [] as $violation) {
            return $violation->getMessage();
        }

        if ($exception instanceof InvalidCsrfTokenException) {
            return $this->translator->trans('exceptions.security.csrf_invalid', domain: 'exceptions');
        }

        return $this->translator->trans('exceptions.unexpected', domain: 'exceptions');
    }
}
