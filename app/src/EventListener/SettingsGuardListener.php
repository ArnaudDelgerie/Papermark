<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\SettingRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Keeps the user on /settings until the images folder is set (see
 * EDITOR_IMAGES.md): the editor needs it to resolve and store images.
 */
#[AsEventListener(event: KernelEvents::REQUEST)]
final class SettingsGuardListener
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($event->getRequest()->attributes->get('_route') !== 'app_home') {
            return;
        }

        if ($this->settings->getOrCreate()->hasImageFolder()) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_settings')));
    }
}
