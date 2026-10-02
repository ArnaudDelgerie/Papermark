<?php

declare(strict_types=1);

namespace App\Tests\Double;

use ArnaudDelgerie\TFSAppBundle\Bridge\BackendCloseGuardInterface;

/**
 * Close guard stand-in for tests: the real BackendCloseGuard needs the
 * TFSApp hub bridge (lot 02, gardes de fermeture). Records every call in
 * order, and can be told to answer `false` (no bridge) or to throw, for
 * the tests of what a missing or failing guard changes.
 */
final class RecordingCloseGuard implements BackendCloseGuardInterface
{
    /** @var list<array{0: 'register'|'remove', 1: string}> */
    public array $calls = [];

    /** False reads as "no bridge": the guard was never installed. */
    public bool $available = true;

    public bool $throwsOnRegister = false;

    public bool $throwsOnRemove = false;

    public function register(string $id): bool
    {
        $this->calls[] = ['register', $id];

        if ($this->throwsOnRegister) {
            throw new \RuntimeException('The bridge is unreachable.');
        }

        return $this->available;
    }

    public function remove(string $id): bool
    {
        $this->calls[] = ['remove', $id];

        if ($this->throwsOnRemove) {
            throw new \RuntimeException('The bridge went away mid-work.');
        }

        return $this->available;
    }
}
