<?php

declare(strict_types=1);

namespace App\File;

/**
 * What PathPolicy::write() decided: the canonical path when allowed, the
 * refusal when not. Reading and listing have a single refusal — the route
 * answers 404 — so they return the path alone.
 */
final readonly class PathPermission
{
    private function __construct(
        public readonly ?string $path,
        public readonly ?PathRefusal $refusal,
    ) {
    }

    public static function allowed(string $path): self
    {
        return new self($path, null);
    }

    public static function refused(PathRefusal $refusal): self
    {
        return new self(null, $refusal);
    }
}
