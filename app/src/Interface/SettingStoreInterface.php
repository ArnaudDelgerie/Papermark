<?php

declare(strict_types=1);

namespace App\Interface;

use App\Entity\Setting;
use App\Enum\ProviderName;
use App\Enum\Setting\AppLocale;
use App\Enum\Setting\EditorMode;
use App\Enum\Setting\ThemeMode;
use App\Service\Setting\CachedSettingStore;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Read side of the app-wide settings. Reads only: a change starts from the
 * entity read by SettingRepository::getOrCreate(), never from a stored value.
 */
#[AsAlias(CachedSettingStore::class)]
interface SettingStoreInterface
{
    public function getDefaultMode(): EditorMode;

    public function getThemeMode(): ThemeMode;

    public function getLocale(): AppLocale;

    public function getSelectedProviderName(): ?ProviderName;

    /** Saves the document after a delay without typing (EDITOR_AUTOSAVE.md). */
    public function isAutosave(): bool;

    /** Saves the document when an AI task ends on a modified document. */
    public function isAutosaveAfterAi(): bool;

    /**
     * Called after the Setting row was written: replaces what reads see.
     */
    public function update(Setting $setting): void;
}
