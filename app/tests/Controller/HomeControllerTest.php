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
}
