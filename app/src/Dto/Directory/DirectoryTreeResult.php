<?php

declare(strict_types=1);

namespace App\Dto\Directory;

final class DirectoryTreeResult
{
    private function __construct(
        public readonly ?DirectoryNode $root,
        public readonly bool $tooLarge,
    ) {
    }

    public static function ok(DirectoryNode $root): self
    {
        return new self($root, false);
    }

    public static function tooLarge(): self
    {
        return new self(null, true);
    }
}
