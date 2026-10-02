<?php

declare(strict_types=1);

namespace App\Service\CloseGuard;

use ArnaudDelgerie\TFSAppBundle\Bridge\BackendCloseGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs a closure under a hub close guard (see the bundle README, "Backend
 * close guards"): closing the hub's last window while the work runs asks
 * the hub's confirmation instead of killing it mid-flight. This is the only
 * place that talks to BackendCloseGuardInterface — register() before the
 * work, remove() in a finally, and the work never fails because of the
 * guard itself: a guard that could not be registered (no bridge, or a hub
 * refusal) lets the work run unguarded, and a failed remove is logged and
 * swallowed. The id is the caller's: distinct ids for concurrent works,
 * 16 guards at most per space, no expiry.
 */
final class BackendCloseGuardRunner
{
    public function __construct(
        private readonly BackendCloseGuardInterface $closeGuards,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function run(string $id, \Closure $work): mixed
    {
        $guarded = false;
        try {
            $guarded = $this->closeGuards->register($id);
        } catch (\Throwable $e) {
            $this->logger->warning('Close guard {id} could not be registered: {message}', ['id' => $id, 'message' => $e->getMessage(), 'exception' => $e]);
        }

        try {
            return $work();
        } finally {
            // Only the owner removes its guard: register() answering false
            // means no guard exists, remove would be a call for a guard this
            // code does not own.
            if ($guarded) {
                try {
                    $this->closeGuards->remove($id);
                } catch (\Throwable $e) {
                    $this->logger->warning('Close guard {id} could not be removed: {message}', ['id' => $id, 'message' => $e->getMessage(), 'exception' => $e]);
                }
            }
        }
    }
}
