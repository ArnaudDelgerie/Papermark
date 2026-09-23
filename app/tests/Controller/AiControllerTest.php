<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AiRequest;
use App\Enum\AiRequestStatus;
use App\Message\AiInstructionMessage;
use App\Repository\AiRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class AiControllerTest extends WebTestCase
{
    /**
     * The token is stateless: a fixed value, checked by the request's
     * origin. It is read from the container.
     *
     * @return array{0: KernelBrowser, 1: string}
     */
    private function createClientWithCsrf(): array
    {
        $client = static::createClient();
        $client->request('GET', '/editor');
        $client->disableReboot();

        $csrfToken = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('papermark_app')->getValue();

        return [$client, $csrfToken];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(KernelBrowser $client, string $uri, string $csrfToken, array $body = []): void
    {
        $client->request('POST', $uri, $body, [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);
    }

    public function testInstructRejectsInvalidCsrf(): void
    {
        $client = static::createClient();
        $this->post($client, '/ai/instruct', 'invalid', [
            'id' => Uuid::v4()->toRfc4122(),
            'instruction' => 'Improve writing',
            'document' => 'Hello',
        ]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['genericErrors']);
    }

    public function testInstructRejectsMissingInstruction(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $this->post($client, '/ai/instruct', $csrfToken, [
            'id' => Uuid::v4()->toRfc4122(),
            'instruction' => '',
            'document' => 'Hello',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testInstructRejectsInvalidRequestId(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $this->post($client, '/ai/instruct', $csrfToken, [
            'id' => 'not-a-uuid',
            'instruction' => 'Improve writing',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testInstructDispatchesMessageOnSessionTopic(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $ids = [Uuid::v4()->toRfc4122(), Uuid::v4()->toRfc4122()];

        // The in-memory transport is reset between requests: read it after each one.
        $messages = [];
        foreach ($ids as $id) {
            $this->post($client, '/ai/instruct', $csrfToken, [
                // Ignored: the worker reads the selection from the database.
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'id' => $id,
                'instruction' => 'Improve writing',
                'document' => 'Hello world',
                'selection' => 'Hello',
            ]);
            self::assertResponseStatusCodeSame(202);

            /** @var InMemoryTransport $transport */
            $transport = static::getContainer()->get('messenger.transport.async');
            $sent = $transport->getSent();
            self::assertCount(1, $sent);
            $messages[] = $sent[0]->getMessage();
        }

        [$first, $second] = $messages;
        self::assertInstanceOf(AiInstructionMessage::class, $first);
        self::assertInstanceOf(AiInstructionMessage::class, $second);
        self::assertSame($ids[0], $first->requestId);
        self::assertSame($ids[1], $second->requestId);
        self::assertStringStartsWith('ai/', $first->topic);
        self::assertSame($first->topic, $second->topic);
        self::assertSame('Improve writing', $first->instruction);
        self::assertSame('Hello world', $first->document);
        self::assertSame('Hello', $first->selection);

        // Each instruction leaves a durable pending row, keyed by the request id.
        foreach ($ids as $id) {
            self::assertSame(AiRequestStatus::Pending, $this->requestRow($client, $id)->getStatus());
        }
    }

    public function testSubscribeRejectsInvalidCsrf(): void
    {
        $client = static::createClient();
        $this->post($client, '/ai/subscribe', 'invalid');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSubscribeMintsCookieOnFirstCallThenSkipsWhileFresh(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $this->post($client, '/ai/subscribe', $csrfToken);
        self::assertResponseStatusCodeSame(204);
        self::assertResponseHasCookie('mercureAuthorization', '/.well-known/mercure');

        // The cookie is still fresh: the server must not re-mint it.
        $this->post($client, '/ai/subscribe', $csrfToken);
        self::assertResponseStatusCodeSame(204);
        self::assertResponseNotHasCookie('mercureAuthorization', '/.well-known/mercure');
    }

    public function testSubscribeReMintCookieWhenNearExpiry(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $this->post($client, '/ai/subscribe', $csrfToken);
        self::assertResponseHasCookie('mercureAuthorization', '/.well-known/mercure');

        // Simulate a cookie about to expire: less than 30 min remaining.
        $session = $client->getRequest()->getSession();
        $session->set('_ai_mercure_cookie_expires_at', time() + 60);
        $session->save();

        $this->post($client, '/ai/subscribe', $csrfToken);
        self::assertResponseStatusCodeSame(204);
        self::assertResponseHasCookie('mercureAuthorization', '/.well-known/mercure');
    }

    public function testAbortRejectsInvalidCsrf(): void
    {
        $client = static::createClient();
        $this->post($client, '/ai/abort', 'invalid', ['id' => Uuid::v4()->toRfc4122()]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAbortMarksPendingRequestAborted(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $id = Uuid::v4()->toRfc4122();

        $this->post($client, '/ai/instruct', $csrfToken, [
            'id' => $id,
            'instruction' => 'Improve writing',
            'document' => 'Hello',
        ]);
        self::assertSame(AiRequestStatus::Pending, $this->requestRow($client, $id)->getStatus());

        $this->post($client, '/ai/abort', $csrfToken, ['id' => $id]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(AiRequestStatus::Aborted, $this->requestRow($client, $id)->getStatus());
    }

    public function testAbortOfUnknownRequestIsAcceptedAndCreatesNothing(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $this->post($client, '/ai/abort', $csrfToken, ['id' => Uuid::v4()->toRfc4122()]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->requestRepository($client)->findAll());
    }

    /**
     * Reads the row back from the database, not from the identity map the
     * request just wrote through.
     */
    private function requestRow(KernelBrowser $client, string $id): AiRequest
    {
        $client->getContainer()->get(EntityManagerInterface::class)->clear();

        return $this->requestRepository($client)->find($id);
    }

    private function requestRepository(KernelBrowser $client): AiRequestRepository
    {
        return $client->getContainer()->get(AiRequestRepository::class);
    }
}
