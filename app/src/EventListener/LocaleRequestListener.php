<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\SettingRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies the persisted locale (Setting::$locale) to every request. Runs
 * after routing (priority 32) but before the framework's LocaleListener/
 * LocaleAwareListener (16/15), so our value is what gets synced to the
 * translator instead of being overwritten by the default locale.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 17)]
final class LocaleRequestListener
{
    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->setLocale($this->settings->getOrCreate()->getLocale()->value);
    }
}
