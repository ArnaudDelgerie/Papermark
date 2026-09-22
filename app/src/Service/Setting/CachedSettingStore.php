<?php

declare(strict_types=1);

namespace App\Service\Setting;

use App\Entity\Setting;
use App\Enum\ProviderName;
use App\Enum\Setting\AppLocale;
use App\Enum\Setting\EditorMode;
use App\Enum\Setting\ThemeMode;
use App\Interface\SettingStoreInterface;
use App\Repository\SettingRepository;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Keeps every value in one cache entry, replaced as a whole. Nothing is kept
 * in memory on top of the pool: a long-lived worker (FrankenPHP, Messenger)
 * would otherwise read stale values.
 *
 * @phpstan-type Values array{defaultMode: EditorMode, themeMode: ThemeMode, locale: AppLocale, selectedProviderName: ?ProviderName}
 */
final class CachedSettingStore implements SettingStoreInterface
{
    private const KEY = 'setting';

    public function __construct(
        #[Target('setting.cache')]
        private readonly CacheInterface $cache,
        private readonly SettingRepository $repository,
    ) {
    }

    public function getDefaultMode(): EditorMode
    {
        return $this->values()['defaultMode'];
    }

    public function getThemeMode(): ThemeMode
    {
        return $this->values()['themeMode'];
    }

    public function getLocale(): AppLocale
    {
        return $this->values()['locale'];
    }

    public function getSelectedProviderName(): ?ProviderName
    {
        return $this->values()['selectedProviderName'];
    }

    public function update(Setting $setting): void
    {
        // A beta of INF forces the callback to run: the entry is rewritten in one go.
        $this->cache->get(self::KEY, fn (): array => $this->toArray($setting), \INF);
    }

    /**
     * @return Values
     */
    private function values(): array
    {
        return $this->cache->get(self::KEY, fn (): array => $this->toArray($this->repository->getOrCreate()));
    }

    /**
     * @return Values
     */
    private function toArray(Setting $setting): array
    {
        return [
            'defaultMode' => $setting->getDefaultMode(),
            'themeMode' => $setting->getThemeMode(),
            'locale' => $setting->getLocale(),
            'selectedProviderName' => $setting->getSelectedProvider()?->getName(),
        ];
    }
}
