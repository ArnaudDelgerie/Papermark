<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\Setting\ThemeMode;
use App\Setting\SettingStore;
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
