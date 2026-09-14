<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Mistral\Factory as MistralFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds a Platform at runtime from the user-chosen provider and API key.
 *
 * The provider and key are not known at deploy time — the user picks them via
 * the TFSApp keyring — so we cannot wire a single platform in YAML. This factory
 * calls each bridge's Factory::createPlatform() with the resolved key.
 */
final class AiPlatformFactory
{
    /** @var array<string, string> default model per provider */
    private const DEFAULT_MODELS = [
        'openai' => 'gpt-4o-mini',
        'anthropic' => 'claude-sonnet-4-0',
        'mistral' => 'mistral-large-latest',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return string[] the provider identifiers this factory can build
     */
    public static function supportedProviders(): array
    {
        return array_keys(self::DEFAULT_MODELS);
    }

    public function defaultModel(string $provider): string
    {
        return self::DEFAULT_MODELS[$provider] ?? throw new \InvalidArgumentException(\sprintf('Unknown AI provider "%s".', $provider));
    }

    public function createPlatform(string $provider, string $apiKey): PlatformInterface
    {
        return match ($provider) {
            'openai' => OpenAiFactory::createPlatform($apiKey, $this->httpClient),
            'anthropic' => AnthropicFactory::createPlatform($apiKey, $this->httpClient),
            'mistral' => MistralFactory::createPlatform($apiKey, $this->httpClient),
            default => throw new \InvalidArgumentException(\sprintf('Unknown AI provider "%s".', $provider)),
        };
    }
}
