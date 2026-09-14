<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\AiInstructionMessage;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
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
            'instruction' => '',
            'document' => 'Hello',
        ]));

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testInstructDispatchesMessageWithoutClientProviderOrModel(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $client->disableReboot();

        $client->request('POST', '/ai/instruct', [], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            // Ignored: the worker reads the selection from the database.
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'instruction' => 'Improve writing',
            'document' => 'Hello world',
            'selection' => 'Hello',
        ]));

        self::assertResponseIsSuccessful();
        $topic = json_decode($client->getResponse()->getContent(), true)['topic'];

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);

        $message = $sent[0]->getMessage();
        self::assertInstanceOf(AiInstructionMessage::class, $message);
        self::assertSame($topic, $message->getTopic());
        self::assertSame('Improve writing', $message->getInstruction());
        self::assertSame('Hello world', $message->getDocument());
        self::assertSame('Hello', $message->getSelection());
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
