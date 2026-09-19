<?php

declare(strict_types=1);

namespace App\Twig;

use App\Setting\SettingStore;
use App\Theme\ThemeMode;
use Twig\Attribute\AsTwigFunction;

final class ThemeExtension
{
    public function __construct(
        private readonly SettingStore $settings,
    ) {
    }

    #[AsTwigFunction('app_theme_mode')]
    public function themeMode(): ThemeMode
    {
        return $this->settings->getThemeMode();
    }
}
