<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SettingsControllerTest extends WebTestCase
{
    /**
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: string}
     */
    private function createClientWithCsrf(): array
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $csrfToken = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('settings')->getValue();

        return [$client, $csrfToken];
    }

    public function testSettingsPageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Settings');
        self::assertSelectorExists('a.settings-back');
    }

    public function testSaveAiRejectsInvalidCsrf(): void
    {
        $client = static::createClient();
        $client->request('POST', '/settings/ai', [
            'provider' => 'openai',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testSaveAiRejectsInvalidProvider(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/settings/ai', [
            'provider' => 'grok',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }
}
