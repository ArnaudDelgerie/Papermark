<?php

declare(strict_types=1);

namespace App\Tests\Double;

use ArnaudDelgerie\TFSAppBundle\StationContext\StationContextInterface;

/**
 * Station context stand-in whose probes can change between requests: the
 * container keeps one instance for the whole test (it cannot be replaced
 * once initialized), so a test flips the public properties instead.
 */
final class StationContextDouble implements StationContextInterface
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

    public function isRunningUnderStation(): bool
    {
        return true;
    }
}
