<?php

declare(strict_types=1);

namespace App\File;

final class DirectoryFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        /** A symbolic link: listed and readable, but never renamed or deleted (lot 02-chemins.md). */
        public readonly bool $symlink = false,
    ) {
    }
}
