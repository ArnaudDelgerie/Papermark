<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\AiRequestStatus;
use App\Message\AiInstructionMessage;
use App\Repository\AiRequestRepository;
use App\Repository\SettingRepository;
use App\Service\Ai\AiPlatformFactory;
use App\Service\Ai\ApiKeyResolver;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Execution\Update\Result as ResultUpdate;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformExceptionInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Consumes an AiInstructionMessage, calls the LLM via symfony/ai-bundle,
 * and streams each text token to the session's Mercure topic.
 *
 * The provider and model are read from the database when the message is
 * consumed, never taken from the client. Every event carries the request id, so
 * the browser ignores events of another request. A final "done" or "error"
 * event ends the request; an aborted request publishes nothing more.
 *
 * This handler never raises: a raised exception would leave the client hanging
 * and make Messenger replay a paid call. Everything is caught, the final event
 * is published best-effort, and a request's durable state lives in AiRequest,
 * whose "expired" take covers a message the transport redelivers later.
 */
#[AsMessageHandler]
final class AiInstructionHandler
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a writing assistant embedded in a markdown editor.

        Rules:
        - Output markdown only. Never wrap your output in code fences (e.g. ```markdown ... ```).
        - Never include preambles, explanations, or sign-offs — output only the edited or generated content itself.
        - Preserve the original markdown structure (headings, lists, links, code blocks) unless the instruction explicitly asks to change it.
        - If a <selection> is provided, return only the replacement for that selection — do not repeat surrounding document context.
        - If no <selection> is provided, return content to insert at the cursor that flows with the surrounding document.
        PROMPT;

    private const TRANSLATION_DOMAIN = 'components';

    public function __construct(
        private readonly AiPlatformFactory $platformFactory,
        private readonly ApiKeyResolver $apiKeyResolver,
        private readonly SettingRepository $settings,
        private readonly AiRequestRepository $requests,
        private readonly HubInterface $hub,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly LocaleSwitcher $localeSwitcher,
        /** The abort status is re-read from the database at most this often during a stream. */
        private readonly float $abortCheckInterval = 0.5,
    ) {
    }

    public function __invoke(AiInstructionMessage $message): void
    {
        // The worker serves no request: it speaks the language of the settings,
        // like it reads the provider and model from them.
        try {
            $locale = $this->settings->getOrCreate()->getLocale()->value;
            $this->localeSwitcher->runWithLocale($locale, fn () => $this->handle($message));
        } catch (\Throwable $e) {
            // Last resort: handle() catches everything itself, so this only
            // fires on an unexpected failure before it, and the client's
            // indicator is the remaining escape hatch.
            $this->logger->error('AI instruction failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    private function handle(AiInstructionMessage $message): void
    {
        $request = $this->requests->claim($message->requestId);
        if ($request === null) {
            // Unknown, already aborted or finished, or stale: no provider call.
            return;
        }

        $promptTokens = null;
        $completionTokens = null;

        try {
            $provider = $this->settings->getOrCreate()->getSelectedProvider();
            if ($provider === null) {
                $this->refuse($message, 'components.editor.error.provider_not_selected');
                return;
            }

            $model = $provider->getModel();
            if ($model === null || $model === '') {
                $this->refuse($message, 'components.editor.error.model_missing');
                return;
            }

            $apiKey = $this->apiKeyResolver->resolve($provider->getName());
            if ($apiKey === null) {
                $this->refuse($message, 'components.editor.error.api_key_missing');
                return;
            }

            $platform = $this->platformFactory->createPlatform($provider->getName(), $apiKey);

            $agent = new Agent(
                platform: $platform,
                model: $model,
                inputProcessors: [
                    new SystemPromptInputProcessor(self::SYSTEM_PROMPT),
                ],
            );

            $this->requests->recordRun($request, $provider->getName(), $model);

            $userMessage = $this->buildUserMessage($message);
            $messages = new MessageBag(Message::ofUser($userMessage));

            $execution = $agent->call($messages, ['stream' => true]);

            $hasText = false;
            $lastAbortCheck = 0.0;
            foreach ($execution as $update) {
                if ($update instanceof Progress && 'delta' === $update->getStage()) {
                    $delta = $update->getPayload();
                    if ($delta instanceof TextDelta) {
                        $hasText = true;
                        $this->publish($message, ['type' => 'chunk', 'content' => $delta->getText()]);
                    }
                } elseif ($update instanceof ResultUpdate) {
                    // Token usage never reaches the delta stream: the
                    // platform's stream listener aggregates it into the final
                    // result's metadata.
                    $usage = $update->getResult()->getMetadata()->get('token_usage');
                    if ($usage instanceof TokenUsageInterface) {
                        $promptTokens = $usage->getPromptTokens();
                        $completionTokens = $usage->getCompletionTokens();
                    }
                }

                // Re-read the abort status at most every 500 ms, whatever the
                // update: thinking deltas publish nothing but still bill.
                if (microtime(true) - $lastAbortCheck >= $this->abortCheckInterval) {
                    $lastAbortCheck = microtime(true);
                    if ($this->requests->isAborted($message->requestId)) {
                        // Leaving the loop releases the HTTP response, which closes the provider connection.
                        return;
                    }
                }
            }

            if (!$hasText) {
                $this->requests->finish($message->requestId, AiRequestStatus::Failed, $promptTokens, $completionTokens);
                $this->publishFinal($message, ['type' => 'error', 'error' => $this->translate('components.editor.error.ai_empty_response')]);

                return;
            }

            $this->requests->finish($message->requestId, AiRequestStatus::Done, $promptTokens, $completionTokens);
            $this->publishFinal($message, ['type' => 'done']);
        } catch (PlatformExceptionInterface|TransportExceptionInterface $e) {
            $this->logger->error('AI instruction failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $this->requests->finish($message->requestId, AiRequestStatus::Failed, $promptTokens, $completionTokens);
            // symfony/ai and transport exceptions carry the provider's own
            // message ("model not found", no raw JSON); anything else stays generic.
            $this->publishFinal($message, ['type' => 'error', 'error' => $this->translator->trans('components.editor.error.ai_failed_with_message', ['{message}' => $this->providerDetail($e)], self::TRANSLATION_DOMAIN)]);
        } catch (\Throwable $e) {
            $this->logger->error('AI instruction failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $this->requests->finish($message->requestId, AiRequestStatus::Failed, $promptTokens, $completionTokens);
            $this->publishFinal($message, ['type' => 'error', 'error' => $this->translate('components.editor.error.ai_failed')]);
        }
    }

    private function buildUserMessage(AiInstructionMessage $message): string
    {
        $parts = ["<document>\n{$message->document}\n</document>"];

        if ($message->selection !== '') {
            $parts[] = "<selection>\n{$message->selection}\n</selection>";
        }

        $parts[] = "<instruction>\n{$message->instruction}\n</instruction>";

        return implode("\n\n", $parts);
    }

    /**
     * A configuration problem is the user's to fix: publish the translated
     * refusal and close the request as failed.
     */
    private function refuse(AiInstructionMessage $message, string $key): void
    {
        $this->logger->warning('AI instruction refused: {key}', ['key' => $key]);
        $this->requests->finish($message->requestId, AiRequestStatus::Failed);
        $this->publishFinal($message, ['type' => 'error', 'error' => $this->translate($key)]);
    }

    /**
     * Publishes the event that ends the request. Best-effort: a hub failure is
     * logged and swallowed, so the handler never raises.
     *
     * @param array<string, string> $event
     */
    private function publishFinal(AiInstructionMessage $message, array $event): void
    {
        try {
            $this->publish($message, $event);
        } catch (\Throwable $e) {
            $this->logger->error('Could not publish the final AI event: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * @param array<string, string> $event
     */
    private function publish(AiInstructionMessage $message, array $event): void
    {
        $this->hub->publish(new Update(
            $message->topic,
            json_encode(['id' => $message->requestId] + $event, \JSON_THROW_ON_ERROR),
        ));
    }

    private function translate(string $key): string
    {
        return $this->translator->trans($key, [], self::TRANSLATION_DOMAIN);
    }

    /**
     * A streamed provider error reaches the handler as
     * 'Unexpected response code 404: "{"error":{"message":…}}"', the raw JSON
     * body embedded in the exception message (HttpStatusErrorHandlingTrait
     * cleans the non-streaming paths, but not this one). The user sees the
     * provider's own error.message, never the JSON envelope; the full
     * message is in the log.
     */
    private function providerDetail(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (\preg_match('/^Unexpected response code \d+: "(.*)"\s*$/s', $message, $match) !== 1) {
            return $message;
        }

        try {
            $data = json_decode($match[1], true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $message;
        }

        if (!\is_array($data)) {
            return $message;
        }

        return \is_string($data['error']['message'] ?? null) ? $data['error']['message']
            : (\is_string($data['message'] ?? null) ? $data['message'] : $message);
    }
}
