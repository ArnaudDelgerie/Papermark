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
    private const PROVIDERS = ['openai', 'anthropic', 'mistral'];

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
    public function resolve(string $provider): ?string
    {
        // Try the keyring first — the hub's bridge transport.
        if ($this->secretStore->isAvailable()) {
            try {
                $key = $this->secretStore->get($provider);
                if (\is_string($key) && $key !== '') {
                    return $key;
                }
            } catch (\Throwable) {
                // Bridge declared but group not enabled, or key not declared — fall through.
            }
        }

        // Fall back to env (.env.local in dev, secrets vault in prod).
        $fallback = match ($provider) {
            'openai' => $this->openaiFallback ?? '',
            'anthropic' => $this->anthropicFallback ?? '',
            'mistral' => $this->mistralFallback ?? '',
            default => '',
        };

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * Returns the user-selected provider, or the first provider that has a key
     * if no explicit choice was stored. Null if no provider is configured.
     */
    public function resolveProvider(): ?string
    {
        // Check the keyring for an explicit choice.
        if ($this->secretStore->isAvailable()) {
            try {
                $provider = $this->secretStore->get('ai_provider');
                if (\is_string($provider) && \in_array($provider, self::PROVIDERS, true)) {
                    return $provider;
                }
            } catch (\Throwable) {
                // Fall through to auto-detection.
            }
        }

        // Auto-detect: first provider with a key.
        foreach (self::PROVIDERS as $provider) {
            if ($this->resolve($provider) !== null) {
                return $provider;
            }
        }

        return null;
    }
}
