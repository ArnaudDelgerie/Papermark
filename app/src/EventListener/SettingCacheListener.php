<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Setting;
use App\Interface\SettingStoreInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Keeps the SettingStoreInterface in step with the Setting row, whoever flushes it.
 */
#[AsEntityListener(event: Events::postPersist, entity: Setting::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Setting::class)]
final class SettingCacheListener
{
    public function __construct(
        private readonly SettingStoreInterface $store,
    ) {
    }

    public function __invoke(Setting $setting): void
    {
        $this->store->update($setting);
    }
}
