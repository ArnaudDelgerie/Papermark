<?php

declare(strict_types=1);

namespace App\Ai;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Carries an abort from the web process to the worker.
 *
 * cache.app is a filesystem pool shared by both processes. Without it, the single
 * worker would finish a cancelled generation before handling the next request.
 */
final class AiAbortRegistry
{
    private const TTL = 300;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function abort(string $requestId): void
    {
        $item = $this->cache->getItem($this->key($requestId));
        $item->set(true)->expiresAfter(self::TTL);
        $this->cache->save($item);
    }

    public function isAborted(string $requestId): bool
    {
        return $this->cache->hasItem($this->key($requestId));
    }

    private function key(string $requestId): string
    {
        return 'ai_abort_' . $requestId;
    }
}
