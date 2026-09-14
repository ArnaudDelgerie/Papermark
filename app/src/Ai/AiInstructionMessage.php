<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Carries an AI instruction from the HTTP request to the async worker.
 *
 * The worker calls the LLM, streams tokens to a Mercure topic, and publishes
 * a final "done" or "error" event so the browser can close its EventSource.
 */
#[AsMessage]
final class AiInstructionMessage
{
    /**
     * @param non-empty-string $provider  "openai" | "anthropic" | "mistral"
     * @param string           $model     model identifier (e.g. "gpt-4o-mini")
     * @param non-empty-string $topic     Mercure topic to publish streamed tokens to
     * @param non-empty-string $instruction the user's prompt or suggestion prompt
     * @param string           $document  full document markdown (for context)
     * @param string           $selection selected text (empty if no selection)
     */
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $topic,
        private readonly string $instruction,
        private readonly string $document,
        private readonly string $selection = '',
    ) {
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getInstruction(): string
    {
        return $this->instruction;
    }

    public function getDocument(): string
    {
        return $this->document;
    }

    public function getSelection(): string
    {
        return $this->selection;
    }
}
