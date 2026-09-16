<?php

namespace App\Tests\Controller;

use App\Editor\EditorMode;
use App\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testRedirectsToSingleModeByDefault(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseRedirects('/editor/single');
    }

    public function testRedirectsToDirModeWhenSelected(): void
    {
        $client = static::createClient();

        $setting = static::getContainer()->get(SettingRepository::class)->getOrCreate();
        $setting->setDefaultMode(EditorMode::Dir);
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $client->request('GET', '/');

        self::assertResponseRedirects('/editor/dir');
    }

    public function testRedirectsToModeRememberedInSessionOverTheDefault(): void
    {
        $client = static::createClient();
        // The default stays Single; visiting dir mode once should still win.
        $client->request('GET', '/editor/dir');

        $client->request('GET', '/');

        self::assertResponseRedirects('/editor/dir');
    }
}
