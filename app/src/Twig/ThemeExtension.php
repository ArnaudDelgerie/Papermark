<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\Setting\ThemeMode;
use App\Interface\SettingStoreInterface;
use Twig\Attribute\AsTwigFunction;

final class ThemeExtension
{
    public function __construct(
        private readonly SettingStoreInterface $settings,
    ) {
    }

    #[AsTwigFunction('app_theme_mode')]
    public function themeMode(): ThemeMode
    {
        return $this->settings->getThemeMode();
    }
}
