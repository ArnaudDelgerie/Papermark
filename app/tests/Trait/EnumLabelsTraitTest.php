<?php

declare(strict_types=1);

namespace App\Tests\Trait;

use App\Enum\Setting\AppLocale;
use App\Enum\Setting\ThemeMode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EnumLabelsTraitTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function translator(): TranslatorInterface
    {
        return self::getContainer()->get(TranslatorInterface::class);
    }

    public function testThemeModeValuesFollowTheCycleOrder(): void
    {
        self::assertSame(['auto', 'light', 'dark'], ThemeMode::values());
    }

    public function testThemeModeLabelsInEnglish(): void
    {
        // labels() has no locale argument: it reads the translator's current
        // (default) locale, 'en' in tests.
        self::assertSame(
            ['auto' => 'Automatic', 'light' => 'Light', 'dark' => 'Dark'],
            ThemeMode::labels($this->translator()),
        );
    }

    public function testThemeModeLabelsInFrench(): void
    {
        $labels = array_map(
            fn (ThemeMode $mode): string => $mode->trans($this->translator(), 'fr'),
            ThemeMode::cases(),
        );

        self::assertSame(['Automatique', 'Clair', 'Sombre'], $labels);
    }

    public function testAppLocaleValues(): void
    {
        self::assertSame(['en', 'fr'], AppLocale::values());
    }

    public function testAppLocaleLabelsInEnglishAndFrench(): void
    {
        self::assertSame(['en' => 'English', 'fr' => 'Français'], AppLocale::labels($this->translator()));

        $labels = array_map(
            fn (AppLocale $locale): string => $locale->trans($this->translator(), 'fr'),
            AppLocale::cases(),
        );
        self::assertSame(['English', 'Français'], $labels);
    }
}
