<?php

declare(strict_types=1);

namespace App\Tests\Double;

use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;

/**
 * Keyring stand-in for tests: the real SecretStore needs the TFSApp hub bridge.
 * `available` flips for the tests of what a missing bridge changes (HUB-06,
 * lot 09): the container cannot replace an initialized service, but it
 * re-reads the property each request.
 */
final class InMemorySecretStore implements SecretStoreInterface
{
    public bool $available = true;

    /**
     * @param array<string, string> $secrets
     */
    public function __construct(
        private array $secrets = [],
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function keys(): array
    {
        return [];
    }

    public function has(string $key): bool
    {
        return isset($this->secrets[$key]);
    }

    public function get(string $key): ?string
    {
        return $this->secrets[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->secrets[$key] = $value;
    }

    public function delete(string $key): bool
    {
        $existed = isset($this->secrets[$key]);
        unset($this->secrets[$key]);

        return $existed;
    }
}
