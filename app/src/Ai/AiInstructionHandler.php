<?php

declare(strict_types=1);

namespace App\Ai;

use App\Repository\SettingRepository;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformExceptionInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Consumes an AiInstructionMessage, calls the LLM via symfony/ai-bundle,
 * and streams each text token to the session's Mercure topic.
 *
 * The provider and model are read from the database when the message is
 * consumed, never taken from the client. Every event carries the request id, so
 * the browser ignores events of another request. A final "done" or "error" event
 * ends the request; an aborted request publishes nothing more.
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

    private const TRANSLATION_PREFIX = 'components.editor.error.';
    private const TRANSLATION_DOMAIN = 'components';

    public function __construct(
        private readonly AiPlatformFactory $platformFactory,
        private readonly ApiKeyResolver $apiKeyResolver,
        private readonly SettingRepository $settings,
        private readonly HubInterface $hub,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly AiAbortRegistry $abortRegistry,
    ) {
    }

    public function __invoke(AiInstructionMessage $message): void
    {
        $provider = $this->settings->getOrCreate()->getSelectedProvider();
        if ($provider === null) {
            $this->publishOwnError($message, 'provider_not_selected');
            return;
        }

        $model = $provider->getModel();
        if ($model === null || $model === '') {
            $this->publishOwnError($message, 'model_missing');
            return;
        }

        $apiKey = $this->apiKeyResolver->resolve($provider->getName());
        if ($apiKey === null) {
            $this->publishOwnError($message, 'api_key_missing');
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

        $userMessage = $this->buildUserMessage($message);
        $messages = new MessageBag(Message::ofUser($userMessage));

        try {
            $execution = $agent->call($messages, ['stream' => true]);

            foreach ($execution as $update) {
                if ($update instanceof Progress && 'delta' === $update->getStage()) {
                    $delta = $update->getPayload();
                    if ($delta instanceof TextDelta) {
                        // Leaving the loop releases the HTTP response, which closes the provider connection.
                        if ($this->abortRegistry->isAborted($message->requestId)) {
                            return;
                        }

                        $this->publish($message, ['type' => 'chunk', 'content' => $delta->getText()]);
                    }
                }
            }

            $this->publish($message, ['type' => 'done']);
        } catch (\Throwable $e) {
            $this->logger->error('AI instruction failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            // symfony/ai exceptions carry the provider's own message; anything else stays generic.
            $this->publishError($message, $e instanceof PlatformExceptionInterface
                ? $e->getMessage()
                : $this->translate('ai_failed'));
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

    private function publishOwnError(AiInstructionMessage $message, string $key): void
    {
        $this->logger->warning('AI instruction refused: {key}', ['key' => $key]);
        $this->publishError($message, $this->translate($key));
    }

    private function publishError(AiInstructionMessage $message, string $error): void
    {
        $this->publish($message, ['type' => 'error', 'error' => $error]);
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
        return $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN);
    }
}
