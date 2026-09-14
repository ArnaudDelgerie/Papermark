<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Mistral\Factory as MistralFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds a Platform at runtime from the selected provider and its API key.
 *
 * The provider and key are chosen by the user, not known at deploy time, so we
 * cannot wire a single platform in YAML. Each bridge gets an AnyModelCatalog so
 * the user can type any model name.
 */
final class AiPlatformFactory
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function createPlatform(ProviderName $provider, string $apiKey): PlatformInterface
    {
        $modelCatalog = new AnyModelCatalog($provider);

        return match ($provider) {
            ProviderName::OpenAi => OpenAiFactory::createPlatform($apiKey, $this->httpClient, modelCatalog: $modelCatalog),
            ProviderName::Anthropic => AnthropicFactory::createPlatform($apiKey, $this->httpClient, modelCatalog: $modelCatalog),
            ProviderName::Mistral => MistralFactory::createPlatform($apiKey, $this->httpClient, modelCatalog: $modelCatalog),
        };
    }
}
