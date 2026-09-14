<?php

declare(strict_types=1);

namespace App\Ai;

use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves the API key for a given provider.
 *
 * Primary source is the TFSApp keyring (bridge SecretStore). When the bridge is
 * unavailable — dev outside the hub — falls back to an env var (<PROVIDER>_API_KEY
 * in .env.local). Returns null when neither source has a key, so the caller can
 * signal "not configured" rather than throwing.
 */
final class ApiKeyResolver
{
    public function __construct(
        private readonly SecretStoreInterface $secretStore,
        #[Autowire('%env(default::OPENAI_API_KEY)%')]
        private readonly ?string $openaiFallback = '',
        #[Autowire('%env(default::ANTHROPIC_API_KEY)%')]
        private readonly ?string $anthropicFallback = '',
        #[Autowire('%env(default::MISTRAL_API_KEY)%')]
        private readonly ?string $mistralFallback = '',
    ) {
    }

    /**
     * Returns the API key for the provider, or null if not configured anywhere.
     */
    public function resolve(ProviderName $provider): ?string
    {
        // Try the keyring first — the hub's bridge transport.
        if ($this->secretStore->isAvailable()) {
            try {
                $key = $this->secretStore->get($provider->value);
                if (\is_string($key) && $key !== '') {
                    return $key;
                }
            } catch (\Throwable) {
                // Bridge declared but group not enabled, or key not declared — fall through.
            }
        }

        // Fall back to env (.env.local in dev, secrets vault in prod).
        $fallback = match ($provider) {
            ProviderName::OpenAi => $this->openaiFallback ?? '',
            ProviderName::Anthropic => $this->anthropicFallback ?? '',
            ProviderName::Mistral => $this->mistralFallback ?? '',
        };

        return $fallback !== '' ? $fallback : null;
    }
}
