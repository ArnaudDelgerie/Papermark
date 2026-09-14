<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AiAbortRegistry;
use App\Ai\AiInstructionHandler;
use App\Ai\AiInstructionMessage;
use App\Ai\AiPlatformFactory;
use App\Ai\ApiKeyResolver;
use App\Ai\ProviderName;
use App\Entity\Provider;
use App\Repository\ProviderRepository;
use App\Tests\Double\InMemorySecretStore;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiInstructionHandlerTest extends KernelTestCase
{
    private const REQUEST_ID = '0b7c8f4e-6a47-4a8e-9d6c-2f0f3f0e9b1a';

    /** @var list<array<string, string>> */
    private array $published = [];

    private BufferingLogger $logger;

    private AiAbortRegistry $abortRegistry;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->logger = new BufferingLogger();
        $this->abortRegistry = new AiAbortRegistry(new ArrayAdapter());
    }

    public function testNoSelectedProviderPublishesTranslatedError(): void
    {
        $this->seedProviders(selected: null);

        $this->handle(new MockHttpClient(), ['anthropic' => 'key']);

        self::assertSame([$this->event(['type' => 'error', 'error' => 'No AI provider selected, choose one in the settings'])], $this->published);
        self::assertCount(1, $this->logger->cleanLogs());
    }

    public function testMissingModelPublishesTranslatedError(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic, model: null);

        $this->handle(new MockHttpClient(), ['anthropic' => 'key']);

        self::assertSame([$this->event(['type' => 'error', 'error' => 'No model set for the selected AI provider'])], $this->published);
        self::assertCount(1, $this->logger->cleanLogs());
    }

    public function testMissingApiKeyPublishesTranslatedError(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);

        $this->handle(new MockHttpClient(), []);

        self::assertSame([$this->event(['type' => 'error', 'error' => 'No API key set for the selected AI provider'])], $this->published);
        self::assertCount(1, $this->logger->cleanLogs());
    }

    public function testStreamPublishesChunksThenDone(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);

        $this->handle(new MockHttpClient($this->streamResponse(['Hel', 'lo'])), ['anthropic' => 'key']);

        self::assertSame([
            $this->event(['type' => 'chunk', 'content' => 'Hel']),
            $this->event(['type' => 'chunk', 'content' => 'lo']),
            $this->event(['type' => 'done']),
        ], $this->published);
    }

    public function testAbortedRequestPublishesNothingMore(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->abortRegistry->abort(self::REQUEST_ID);

        $this->handle(new MockHttpClient($this->streamResponse(['Hel', 'lo'])), ['anthropic' => 'key']);

        self::assertSame([], $this->published);
    }

    public function testPlatformExceptionPublishesProviderMessage(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);

        $this->handle(
            new MockHttpClient(new JsonMockResponse(['error' => ['message' => 'invalid x-api-key']], ['http_code' => 401])),
            ['anthropic' => 'key'],
        );

        self::assertSame([$this->event(['type' => 'error', 'error' => 'invalid x-api-key'])], $this->published);
        $this->assertExceptionLogged();
    }

    public function testOtherExceptionPublishesGenericMessage(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);

        $this->handle(
            new MockHttpClient(static fn () => throw new \LogicException('internal detail')),
            ['anthropic' => 'key'],
        );

        self::assertSame([$this->event(['type' => 'error', 'error' => 'The AI request failed'])], $this->published);
        $this->assertExceptionLogged();
    }

    private function seedProviders(?ProviderName $selected, ?string $model = 'claude-test'): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach (ProviderName::cases() as $name) {
            $provider = new Provider($name);
            $provider->setSelected($name === $selected);
            $provider->setModel($model);
            $entityManager->persist($provider);
        }
        $entityManager->flush();
    }

    /**
     * @param list<string> $texts
     */
    private function streamResponse(array $texts): MockResponse
    {
        $body = array_map(
            static fn (string $text): string => "event: content_block_delta\ndata: " . json_encode([
                'type' => 'content_block_delta',
                'delta' => ['type' => 'text_delta', 'text' => $text],
            ]) . "\n\n",
            $texts,
        );

        return new MockResponse($body, ['response_headers' => ['content-type' => 'text/event-stream']]);
    }

    /**
     * @param array<string, string> $secrets
     */
    private function handle(MockHttpClient $httpClient, array $secrets): void
    {
        $container = self::getContainer();
        $container->set(SecretStoreInterface::class, new InMemorySecretStore($secrets));

        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = json_decode($update->getData(), true);

            return 'id';
        });

        $handler = new AiInstructionHandler(
            new AiPlatformFactory($httpClient),
            $container->get(ApiKeyResolver::class),
            $container->get(ProviderRepository::class),
            $hub,
            $container->get(TranslatorInterface::class),
            $this->logger,
            $this->abortRegistry,
        );

        $handler(new AiInstructionMessage('topic', self::REQUEST_ID, 'Improve writing', 'Hello', ''));
    }

    /**
     * @param array<string, string> $event
     *
     * @return array<string, string>
     */
    private function event(array $event): array
    {
        return ['id' => self::REQUEST_ID] + $event;
    }

    private function assertExceptionLogged(): void
    {
        $logs = $this->logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame('error', $logs[0][0]);
        self::assertInstanceOf(\Throwable::class, $logs[0][2]['exception']);
    }
}
