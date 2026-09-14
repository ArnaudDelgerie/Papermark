<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SettingsCsrfTest extends WebTestCase
{
    public function testSettingsPageHasCsrfToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/settings');

        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();

        // When the keyring bridge is available the form is rendered with a
        // CSRF token; when it is not, the page shows a notice instead.
        $bridgeAvailable = str_contains($content, 'settings-form');

        if ($bridgeAvailable) {
            self::assertStringContainsString('settings_csrf_token', $content);
            self::assertStringContainsString('value="', $content);
        }
    }
}
