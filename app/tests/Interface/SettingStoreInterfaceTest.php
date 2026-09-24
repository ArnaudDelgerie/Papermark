<?php

declare(strict_types=1);

namespace App\Tests\Interface;

use App\Enum\Setting\ThemeMode;
use App\Repository\SettingRepository;
use App\Interface\SettingStoreInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SettingStoreInterfaceTest extends KernelTestCase
{
    public function testReadsGoThroughTheCache(): void
    {
        $store = self::getContainer()->get(SettingStoreInterface::class);

        self::assertSame(ThemeMode::Auto, $store->getThemeMode());

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE setting SET theme_mode = ?', [ThemeMode::Light->value]);

        self::assertSame(ThemeMode::Auto, $store->getThemeMode());
    }

    public function testAWriteUpdatesTheCache(): void
    {
        $store = self::getContainer()->get(SettingStoreInterface::class);
        $repository = self::getContainer()->get(SettingRepository::class);

        self::assertSame(ThemeMode::Auto, $store->getThemeMode());

        $setting = $repository->getOrCreate();
        $setting->setThemeMode(ThemeMode::Light);
        $repository->update($setting);

        self::assertSame(ThemeMode::Light, $store->getThemeMode());
    }

    /**
     * SET-07, lot 10: Auto is the default for new installations only. A row
     * that already holds a theme keeps it — the entity default applies when
     * the row is created, never after.
     */
    public function testAnExistingSettingKeepsItsTheme(): void
    {
        $repository = self::getContainer()->get(SettingRepository::class);

        $setting = $repository->getOrCreate();
        $setting->setThemeMode(ThemeMode::Dark);
        $repository->update($setting);

        $store = self::getContainer()->get(SettingStoreInterface::class);

        self::assertSame(ThemeMode::Dark, $store->getThemeMode());
    }
}
