<?php

declare(strict_types=1);

namespace App\Dto\Directory;

final class DirectoryNode
{
    /**
     * @param DirectoryNode[] $directories only directories that contain a .md file at some depth
     * @param DirectoryFile[] $files       .md files directly in this directory
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly array $directories,
        public readonly array $files,
    ) {
    }
}
