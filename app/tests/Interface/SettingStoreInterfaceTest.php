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

        self::assertSame(ThemeMode::Dark, $store->getThemeMode());

        self::getContainer()->get(Connection::class)->executeStatement('UPDATE setting SET theme_mode = ?', [ThemeMode::Light->value]);

        self::assertSame(ThemeMode::Dark, $store->getThemeMode());
    }

    public function testAWriteUpdatesTheCache(): void
    {
        $store = self::getContainer()->get(SettingStoreInterface::class);
        $repository = self::getContainer()->get(SettingRepository::class);

        self::assertSame(ThemeMode::Dark, $store->getThemeMode());

        $setting = $repository->getOrCreate();
        $setting->setThemeMode(ThemeMode::Light);
        $repository->update($setting);

        self::assertSame(ThemeMode::Light, $store->getThemeMode());
    }
}
