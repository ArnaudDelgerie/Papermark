<?php

declare(strict_types=1);

namespace App\File;

use Symfony\Component\Finder\Exception\DirectoryNotFoundException;

/**
 * The .md tree of the directory currently held in session, or null when there
 * is none. Sits between OpenDirectory and DirectoryTree so that the caller —
 * today DirController::tree, inside the sidebar frame — doesn't have to know
 * about either (see EDITOR_FOLDER_MODE.md).
 */
final readonly class OpenDirectoryTree
{
    public function __construct(
        private OpenDirectory $openDirectory,
        private DirectoryTree $directoryTree,
    ) {
    }

    public function build(): ?DirectoryTreeResult
    {
        $directory = $this->openDirectory->get();
        if ($directory === null) {
            return null;
        }

        try {
            return $this->directoryTree->build($directory);
        } catch (DirectoryNotFoundException) {
            // The session held a path that no longer exists (moved, unmounted…).
            return null;
        }
    }
}
