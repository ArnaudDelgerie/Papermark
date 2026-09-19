<?php

declare(strict_types=1);

namespace App\Setting;

use App\Ai\ProviderName;
use App\Editor\EditorMode;
use App\Entity\Setting;
use App\Locale\AppLocale;
use App\Theme\ThemeMode;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Read side of the app-wide settings. Reads only: a change starts from the
 * entity read by SettingRepository::getOrCreate(), never from a stored value.
 */
#[AsAlias(CachedSettingStore::class)]
interface SettingStore
{
    public function getDefaultMode(): EditorMode;

    public function getThemeMode(): ThemeMode;

    public function getLocale(): AppLocale;

    public function getSelectedProviderName(): ?ProviderName;

    /**
     * Called after the Setting row was written: replaces what reads see.
     */
    public function update(Setting $setting): void;
}
