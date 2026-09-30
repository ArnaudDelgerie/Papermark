<?php

declare(strict_types=1);

namespace App\Tests\Double;

use ArnaudDelgerie\TFSAppBundle\HubContext\HubContextInterface;

/**
 * Station context stand-in whose probes can change between requests: the
 * container keeps one instance for the whole test (it cannot be replaced
 * once initialized), so a test flips the public properties instead.
 */
final class HubContextDouble implements HubContextInterface
{
    public bool $worker = true;
    public bool $keyring = true;

    public function identifier(): string
    {
        return 'test-station';
    }

    public function version(): string
    {
        return '0.0.0-test';
    }

    public function isAsyncWorker(): bool
    {
        return $this->worker;
    }

    public function workerTransports(): array
    {
        return [];
    }

    public function isKeyringAvailable(): bool
    {
        return $this->keyring;
    }

    public function isBridgeEnabled(): bool
    {
        return true;
    }

    public function isRunningUnderHub(): bool
    {
        return true;
    }
}
