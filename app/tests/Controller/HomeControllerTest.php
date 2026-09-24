<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    /**
     * Which mode shows is now resolved by the editor page itself, so there is
     * nothing left here to branch on (see EditorControllerTest).
     */
    public function testRedirectsToTheEditor(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseRedirects('/editor');
    }

    /**
     * The favicon is the hub's app icon (UX-08, lot 10): served from the
     * project root, so the file the manifest declares is the only copy.
     */
    public function testServesTheAppIconForTheFavicon(): void
    {
        $client = static::createClient();

        $client->request('GET', '/icon.png');

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getInternalResponse()->getHeader('Content-Type'));
    }
}
