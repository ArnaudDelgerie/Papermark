<?php

declare(strict_types=1);

namespace App\File;

final class DirectoryFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
    ) {
    }
}
