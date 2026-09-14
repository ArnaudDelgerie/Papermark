<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consumes an AiInstructionMessage, calls the LLM via symfony/ai-bundle,
 * and streams each text token to a Mercure topic.
 *
 * The browser subscribes to the same topic via EventSource and yields chunks
 * into Crepe's AIProvider async iterable. A final "done" or "error" event
 * lets the browser close the connection.
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

    public function __construct(
        private readonly AiPlatformFactory $platformFactory,
        private readonly ApiKeyResolver $apiKeyResolver,
        private readonly HubInterface $hub,
    ) {
    }

    public function __invoke(AiInstructionMessage $message): void
    {
        $apiKey = $this->apiKeyResolver->resolve($message->getProvider());
        if ($apiKey === null) {
            $this->publishError($message->getTopic(), 'api_key_missing');
            return;
        }

        $platform = $this->platformFactory->createPlatform($message->getProvider(), $apiKey);

        $agent = new Agent(
            platform: $platform,
            model: $message->getModel(),
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
                        $this->publishChunk($message->getTopic(), $delta->getText());
                    }
                }
            }

            $this->publishDone($message->getTopic());
        } catch (\Throwable $e) {
            $this->publishError($message->getTopic(), $e->getMessage());
        }
    }

    private function buildUserMessage(AiInstructionMessage $message): string
    {
        $parts = ["<document>\n{$message->getDocument()}\n</document>"];

        if ($message->getSelection() !== '') {
            $parts[] = "<selection>\n{$message->getSelection()}\n</selection>";
        }

        $parts[] = "<instruction>\n{$message->getInstruction()}\n</instruction>";

        return implode("\n\n", $parts);
    }

    private function publishChunk(string $topic, string $chunk): void
    {
        $this->hub->publish(new Update(
            $topic,
            json_encode(['type' => 'chunk', 'content' => $chunk], \JSON_THROW_ON_ERROR),
        ));
    }

    private function publishDone(string $topic): void
    {
        $this->hub->publish(new Update(
            $topic,
            json_encode(['type' => 'done'], \JSON_THROW_ON_ERROR),
        ));
    }

    private function publishError(string $topic, string $error): void
    {
        $this->hub->publish(new Update(
            $topic,
            json_encode(['type' => 'error', 'error' => $error], \JSON_THROW_ON_ERROR),
        ));
    }
}
