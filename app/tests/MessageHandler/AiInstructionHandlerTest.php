<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\AiRequest;
use App\Entity\Provider;
use App\Enum\AiRequestStatus;
use App\Enum\ProviderName;
use App\Enum\Setting\AppLocale;
use App\Message\AiInstructionMessage;
use App\MessageHandler\AiInstructionHandler;
use App\Repository\AiRequestRepository;
use App\Repository\SettingRepository;
use App\Service\Ai\AiPlatformFactory;
use App\Service\Ai\ApiKeyResolver;
use App\Service\CloseGuard\BackendCloseGuardRunner;
use App\Tests\Double\InMemorySecretStore;
use App\Tests\Double\RecordingCloseGuard;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiInstructionHandlerTest extends KernelTestCase
{
    private const REQUEST_ID = '0b7c8f4e-6a47-4a8e-9d6c-2f0f3f0e9b1a';

    /** @var list<array<string, string>> */
    private array $published = [];

    private BufferingLogger $logger;

    /** The guard double of the last handle() call, for the call assertions. */
    private RecordingCloseGuard $guards;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->logger = new BufferingLogger();
    }

    public function testNoSelectedProviderPublishesTranslatedError(): void
    {
        $this->seedProviders(selected: null);
        $this->seedRequest();

        $this->handle(new MockHttpClient(), ['anthropic' => 'key']);

        self::assertSame([$this->event(['type' => 'error', 'error' => 'No AI provider selected, choose one in the settings'])], $this->published);
        $this->assertRefusalLogged('components.editor.error.provider_not_selected');
        $this->assertSame(AiRequestStatus::Failed, $this->requestRow()->getStatus());
    }

    public function testMissingModelPublishesTranslatedError(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic, model: null);
        $this->seedRequest();

        $this->handle(new MockHttpClient(), ['anthropic' => 'key']);

        self::assertSame([$this->event(['type' => 'error', 'error' => 'No model set for the selected AI provider'])], $this->published);
        $this->assertRefusalLogged('components.editor.error.model_missing');
        $this->assertSame(AiRequestStatus::Failed, $this->requestRow()->getStatus());
    }

    public function testMissingApiKeyPublishesTranslatedError(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();

        $this->handle(new MockHttpClient(), []);

        self::assertSame([$this->event(['type' => 'error', 'error' => 'No API key set for the selected AI provider'])], $this->published);
        $this->assertRefusalLogged('components.editor.error.api_key_missing');
        $this->assertSame(AiRequestStatus::Failed, $this->requestRow()->getStatus());
    }

    public function testFrenchSettingsMakeTheWorkerPublishFrenchErrors(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic, model: null);
        $this->seedRequest();
        self::getContainer()->get(SettingRepository::class)->getOrCreate()->setLocale(AppLocale::Fr);

        $this->handle(new MockHttpClient(), ['anthropic' => 'key']);

        self::assertSame([$this->event(['type' => 'error', 'error' => 'Aucun modèle défini pour le fournisseur IA sélectionné'])], $this->published);
    }

    public function testStreamPublishesChunksThenDoneAndRecordsTheRun(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();

        $this->handle(new MockHttpClient($this->streamResponse(['Hel', 'lo'])), ['anthropic' => 'key']);

        self::assertSame([
            $this->event(['type' => 'chunk', 'content' => 'Hel']),
            $this->event(['type' => 'chunk', 'content' => 'lo']),
            $this->event(['type' => 'done']),
        ], $this->published);

        $row = $this->requestRow();
        self::assertSame(AiRequestStatus::Done, $row->getStatus());
        self::assertSame(ProviderName::Anthropic, $row->getProvider());
        self::assertSame('claude-test', $row->getModel());
        self::assertSame(12, $row->getPromptTokens());
        self::assertSame(7, $row->getCompletionTokens());
    }

    public function testAbortedBeforeTakeMakesNoProviderCall(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();
        self::getContainer()->get(AiRequestRepository::class)->abort(self::REQUEST_ID);

        $httpClient = $this->handle(new MockHttpClient($this->streamResponse(['Hel', 'lo'])), ['anthropic' => 'key']);

        self::assertSame([], $this->published);
        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertSame(AiRequestStatus::Aborted, $this->requestRow()->getStatus());
    }

    public function testUnknownRequestMakesNoProviderCall(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);

        $httpClient = $this->handle(new MockHttpClient($this->streamResponse(['Hel', 'lo'])), ['anthropic' => 'key']);

        self::assertSame([], $this->published);
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testStaleRequestIsExpiredAtTakeWithoutProviderCall(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest(createdAt: new \DateTimeImmutable('-10 minutes'));

        $httpClient = $this->handle(new MockHttpClient($this->streamResponse(['Hel', 'lo'])), ['anthropic' => 'key']);

        self::assertSame([], $this->published);
        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertSame(AiRequestStatus::Expired, $this->requestRow()->getStatus());
    }

    public function testAbortDuringStreamStopsTheCall(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();
        $requests = self::getContainer()->get(AiRequestRepository::class);

        $body = (function () use ($requests): \Generator {
            yield $this->sse(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hel']]);
            $requests->abort(self::REQUEST_ID);
            yield $this->sse(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'lo']]);
            yield $this->sse(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => '!']]);
        })();

        $httpClient = $this->handle(
            new MockHttpClient(new MockResponse($body, ['response_headers' => ['content-type' => 'text/event-stream']])),
            ['anthropic' => 'key'],
        );

        // The abort lands while the worker is in the delta loop: the check
        // after the first chunk sees it, the rest of the stream is dropped,
        // and no done or error is ever published.
        self::assertSame([
            $this->event(['type' => 'chunk', 'content' => 'Hel']),
        ], $this->published);
        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame(AiRequestStatus::Aborted, $this->requestRow()->getStatus());
    }

    public function testEmptyStreamPublishesNoTextError(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();

        $this->handle(new MockHttpClient($this->streamResponse([])), ['anthropic' => 'key']);

        self::assertSame([$this->event(['type' => 'error', 'error' => 'The model returned no text'])], $this->published);

        $row = $this->requestRow();
        self::assertSame(AiRequestStatus::Failed, $row->getStatus());
        self::assertSame(12, $row->getPromptTokens());
        self::assertSame(7, $row->getCompletionTokens());
    }

    public function testInvalidOpenAiKeyFormatPublishesErrorWithDetail(): void
    {
        $this->seedProviders(selected: ProviderName::OpenAi, model: 'gpt-test');
        $this->seedRequest();

        $this->handle(new MockHttpClient(), ['openai' => 'not-an-openai-key']);

        self::assertSame([$this->event(['type' => 'error', 'error' => 'The AI request failed: The API key must start with "sk-".'])], $this->published);
        $this->assertExceptionLogged();
        self::assertSame(AiRequestStatus::Failed, $this->requestRow()->getStatus());
    }

    public function testProviderErrorPublishesGenericMessageCitingTheProvider(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();

        $this->handle(
            new MockHttpClient(new JsonMockResponse(['error' => ['message' => 'model not found']], ['http_code' => 404])),
            ['anthropic' => 'key'],
        );

        // The provider's own message, no raw JSON body.
        self::assertSame([$this->event(['type' => 'error', 'error' => 'The AI request failed: model not found'])], $this->published);
        $this->assertExceptionLogged();
        self::assertSame(AiRequestStatus::Failed, $this->requestRow()->getStatus());
    }

    public function testFrenchSettingsTranslateTheProviderDetailToo(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();
        self::getContainer()->get(SettingRepository::class)->getOrCreate()->setLocale(AppLocale::Fr);

        $this->handle(
            new MockHttpClient(new JsonMockResponse(['error' => ['message' => 'model not found']], ['http_code' => 404])),
            ['anthropic' => 'key'],
        );

        self::assertSame([$this->event(['type' => 'error', 'error' => "L'appel à l'IA a échoué : model not found"])], $this->published);
    }

    public function testOtherExceptionPublishesGenericMessageWithoutDetail(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();

        $this->handle(
            new MockHttpClient(static fn () => throw new \LogicException('internal detail')),
            ['anthropic' => 'key'],
        );

        self::assertSame([$this->event(['type' => 'error', 'error' => 'The AI request failed'])], $this->published);
        self::assertStringNotContainsString('internal detail', $this->published[0]['error'] ?? '');
        $this->assertExceptionLogged();
        self::assertSame(AiRequestStatus::Failed, $this->requestRow()->getStatus());
    }

    public function testHubFailureIsLoggedAndSwallowed(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();

        $this->handle(
            new MockHttpClient($this->streamResponse(['Hel', 'lo'])),
            ['anthropic' => 'key'],
            static function (Update $update): string {
                throw new \RuntimeException('Mercure is down');
            },
        );

        // The handler does not raise: the chunk publish failure is caught, and
        // the best-effort final publish failure is logged, not rethrown.
        $logs = $this->logger->cleanLogs();
        self::assertCount(2, $logs);
        self::assertSame('AI instruction failed: {message}', $logs[0][1]);
        self::assertSame('Could not publish the final AI event: {message}', $logs[1][1]);
        self::assertSame(AiRequestStatus::Failed, $this->requestRow()->getStatus());
    }

    /**
     * Lot 02 (gardes de fermeture): the worker holds a backend close guard
     * for the whole treatment of a claimed request, so closing the last
     * window while it runs asks the hub's confirmation.
     */
    public function testAClaimedRequestIsGuardedFromRegisterToRemove(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();

        $this->handle(new MockHttpClient($this->streamResponse(['Hel'])), ['anthropic' => 'key']);

        self::assertSame([
            ['register', 'ai:'.self::REQUEST_ID],
            ['remove', 'ai:'.self::REQUEST_ID],
        ], $this->guards->calls);
    }

    public function testARefusedConfigurationRemovesTheGuardToo(): void
    {
        $this->seedProviders(selected: null);
        $this->seedRequest();

        $this->handle(new MockHttpClient(), ['anthropic' => 'key']);

        self::assertSame([
            ['register', 'ai:'.self::REQUEST_ID],
            ['remove', 'ai:'.self::REQUEST_ID],
        ], $this->guards->calls);
    }

    public function testAnUnclaimedRequestNeverTouchesTheGuard(): void
    {
        $this->seedProviders(selected: ProviderName::Anthropic);

        $this->handle(new MockHttpClient($this->streamResponse(['Hel'])), ['anthropic' => 'key']);

        self::assertSame([], $this->guards->calls);
    }

    public function testAGuardTheBridgeRefusedIsNeverRemoved(): void
    {
        $guards = new RecordingCloseGuard();
        $guards->available = false;
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();

        $this->handle(new MockHttpClient($this->streamResponse(['Hel'])), ['anthropic' => 'key'], null, $guards);

        // register() answered false: no guard exists, remove would be a
        // call for a guard this code does not own.
        self::assertSame([['register', 'ai:'.self::REQUEST_ID]], $guards->calls);
        self::assertSame(AiRequestStatus::Done, $this->requestRow()->getStatus());
    }

    public function testARegisterFailureIsLoggedAndTheInstructionRunsUnguarded(): void
    {
        $guards = new RecordingCloseGuard();
        $guards->throwsOnRegister = true;
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();

        $this->handle(new MockHttpClient($this->streamResponse(['Hel'])), ['anthropic' => 'key'], null, $guards);

        self::assertSame([$this->event(['type' => 'chunk', 'content' => 'Hel']), $this->event(['type' => 'done'])], $this->published);
        self::assertSame(AiRequestStatus::Done, $this->requestRow()->getStatus());
        $logs = $this->logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame('warning', $logs[0][0]);
        self::assertSame('Close guard {id} could not be registered: {message}', $logs[0][1]);
        self::assertSame('ai:'.self::REQUEST_ID, $logs[0][2]['id']);
        self::assertInstanceOf(\Throwable::class, $logs[0][2]['exception']);
    }

    public function testARemoveFailureIsLoggedAndSwallowed(): void
    {
        $guards = new RecordingCloseGuard();
        $guards->throwsOnRemove = true;
        $this->seedProviders(selected: ProviderName::Anthropic);
        $this->seedRequest();

        $this->handle(new MockHttpClient($this->streamResponse(['Hel'])), ['anthropic' => 'key'], null, $guards);

        // The instruction completed and nothing surfaced beyond the warning.
        self::assertSame([$this->event(['type' => 'chunk', 'content' => 'Hel']), $this->event(['type' => 'done'])], $this->published);
        $logs = $this->logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame('warning', $logs[0][0]);
        self::assertSame('Close guard {id} could not be removed: {message}', $logs[0][1]);
        self::assertSame('ai:'.self::REQUEST_ID, $logs[0][2]['id']);
        self::assertInstanceOf(\Throwable::class, $logs[0][2]['exception']);
    }

    private function seedProviders(?ProviderName $selected, ?string $model = 'claude-test'): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $selectedProvider = null;
        foreach (ProviderName::cases() as $name) {
            $provider = new Provider($name);
            $provider->setModel($model);
            $entityManager->persist($provider);
            if ($name === $selected) {
                $selectedProvider = $provider;
            }
        }

        $setting = self::getContainer()->get(SettingRepository::class)->getOrCreate();
        $setting->setSelectedProvider($selectedProvider);
        $entityManager->flush();
    }

    private function seedRequest(?\DateTimeImmutable $createdAt = null): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new AiRequest(self::REQUEST_ID, $createdAt));
        $entityManager->flush();
    }

    /**
     * Reads the row back from the database: the worker's own copy is not
     * updated by the conditional status updates, which is the point.
     */
    private function requestRow(): AiRequest
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(AiRequestRepository::class)->find(self::REQUEST_ID);
    }

    /**
     * @param list<string> $texts
     */
    private function streamResponse(array $texts): MockResponse
    {
        $events = [$this->sse(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 12]]])];
        foreach ($texts as $text) {
            $events[] = $this->sse(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => $text]]);
        }
        $events[] = $this->sse(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 7]]);
        $events[] = $this->sse(['type' => 'message_stop']);

        return new MockResponse(implode('', $events), ['response_headers' => ['content-type' => 'text/event-stream']]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sse(array $data): string
    {
        return 'event: stream' . "\n" . 'data: ' . json_encode($data) . "\n\n";
    }

    /**
     * @param array<string, string> $secrets
     */
    private function handle(MockHttpClient $httpClient, array $secrets, ?\Closure $publisher = null, ?RecordingCloseGuard $guards = null): MockHttpClient
    {
        $container = self::getContainer();
        $container->set(SecretStoreInterface::class, new InMemorySecretStore($secrets));
        $this->guards = $guards ?? new RecordingCloseGuard();

        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), $publisher ?? function (Update $update): string {
            $this->published[] = json_decode($update->getData(), true);

            return 'id';
        });

        $handler = new AiInstructionHandler(
            new AiPlatformFactory($httpClient),
            $container->get(ApiKeyResolver::class),
            $container->get(SettingRepository::class),
            $container->get(AiRequestRepository::class),
            $hub,
            $container->get(TranslatorInterface::class),
            $this->logger,
            $container->get(LocaleSwitcher::class),
            new BackendCloseGuardRunner($this->guards, $this->logger),
            // The abort status is checked on every stream update, not throttled.
            0.0,
        );

        $handler(new AiInstructionMessage('topic', self::REQUEST_ID, 'Improve writing', 'Hello', ''));

        return $httpClient;
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

    private function assertRefusalLogged(string $key): void
    {
        $logs = $this->logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame('warning', $logs[0][0]);
        self::assertSame('AI instruction refused: {key}', $logs[0][1]);
        self::assertSame(['key' => $key], $logs[0][2]);
    }
}
