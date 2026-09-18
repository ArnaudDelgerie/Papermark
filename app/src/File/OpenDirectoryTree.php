<?php

declare(strict_types=1);

namespace App\File;

use App\Editor\EditorState;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;

/**
 * The .md tree of the current folder of the EditorState, or null when there
 * is none. Sits between EditorState and DirectoryTree so that the caller —
 * today EditorController::getDir, inside the sidebar frame — doesn't have to
 * know about either (see EDITOR_FOLDER_MODE.md).
 */
final readonly class OpenDirectoryTree
{
    public function __construct(
        private EditorState $editorState,
        private DirectoryTree $directoryTree,
    ) {
    }

    public function build(): ?DirectoryTreeResult
    {
        $directory = $this->editorState->getDir();
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
