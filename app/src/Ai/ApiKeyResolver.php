<?php

declare(strict_types=1);

namespace App\Ai;

use ArnaudDelgerie\TFSAppBundle\Bridge\Exception\BridgeException;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the API key of a provider from the TFSApp keyring.
 *
 * Returns null when the key is not set, and also when the keyring fails: the
 * editor then just offers no AI, and the failure is logged.
 */
final class ApiKeyResolver
{
    public function __construct(
        private readonly SecretStoreInterface $secretStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(ProviderName $provider): ?string
    {
        if (!$this->secretStore->isAvailable()) {
            return null;
        }

        try {
            $key = $this->secretStore->get($provider->value);
        } catch (BridgeException $e) {
            $this->logger->error('Could not read the {provider} API key from the keyring: {message}', [
                'provider' => $provider->value,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }

        return $key !== null && $key !== '' ? $key : null;
    }
}
