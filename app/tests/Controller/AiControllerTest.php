<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AiControllerTest extends WebTestCase
{
    /**
     * The home page renders the Editor component which generates CSRF
     * tokens, setting the session. The token is then read from the container.
     *
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: string}
     */
    private function createClientWithCsrf(): array
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $csrfToken = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('ai')->getValue();

        return [$client, $csrfToken];
    }

    public function testInstructRejectsInvalidCsrf(): void
    {
        $client = static::createClient();
        $client->request('POST', '/ai/instruct', [], [], [
            'HTTP_X-CSRF-TOKEN' => 'invalid',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'provider' => 'openai',
            'instruction' => 'Improve writing',
            'document' => 'Hello',
        ]));

        self::assertResponseStatusCodeSame(403);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testInstructRejectsMissingInstruction(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/ai/instruct', [], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'provider' => 'openai',
            'instruction' => '',
            'document' => 'Hello',
        ]));

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testInstructRejectsInvalidProvider(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/ai/instruct', [], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'provider' => 'grok',
            'instruction' => 'Improve writing',
            'document' => 'Hello',
        ]));

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testConfigReturnsProviderStatus(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ai/config');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('enabled', $data);
        self::assertArrayHasKey('providers', $data);
        self::assertArrayHasKey('openai', $data['providers']);
        self::assertArrayHasKey('anthropic', $data['providers']);
        self::assertArrayHasKey('mistral', $data['providers']);
    }
}
