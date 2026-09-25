<?php

declare(strict_types=1);

namespace App\Service\Editor;

use App\Dto\Editor\OpenedPath;
use App\Enum\Setting\EditorMode;
use App\Exception\Path\PathNotFoundException;
use App\Service\Directory\OpenDirectoryTree;
use App\Service\Document\DocumentStore;
use App\Service\Path\PathPolicy;

/**
 * The operation service behind the routes that write the EditorState (lot
 * 05-editor-navigator.md): set the mode, set or close the current file, set
 * the current folder, refresh its cached walk.
 */
final class EditorNavigator
{
    public function __construct(
        private readonly EditorState $editorState,
        private readonly DocumentStore $documentStore,
        private readonly PathPolicy $pathPolicy,
        private readonly OpenDirectoryTree $openDirectoryTree,
    ) {
    }

    public function setMode(EditorMode $mode): void
    {
        $this->editorState->setMode($mode);
    }

    /**
     * Checked the same way as the content read (absent, symbolic link, not a
     * file, not UTF-8): DocumentStore::read does both jobs, the conversion
     * and the revision are simply unused here.
     *
     * @return string the real path
     *
     * @throws PathNotFoundException
     */
    public function openFile(string $path): string
    {
        try {
            $document = $this->documentStore->read($path);
        } catch (PathNotFoundException $e) {
            // Only the current file found gone is dropped — a bad path
            // picked by hand must not clear it.
            if ($this->editorState->getFile() === $path) {
                $this->editorState->setFile(null);
            }

            throw $e;
        }

        $this->editorState->setFile($document->path);

        return $document->path;
    }

    public function closeFile(): void
    {
        $this->editorState->setFile(null);
    }

    /**
     * @return string the real path
     *
     * @throws PathNotFoundException
     */
    public function openDir(string $path): string
    {
        $realPath = $this->pathPolicy->list($path);

        $this->editorState->setDir($realPath);

        return $realPath;
    }

    public function refreshDir(): void
    {
        $this->openDirectoryTree->forget();
    }

    /**
     * Opens a path without knowing in advance what it is (lot 04a): a folder
     * switches to dir mode on that folder, anything else is read as a file,
     * with openFile()'s refusals — minus its current-file-gone special case,
     * which doesn't apply: nobody was showing this path yet.
     *
     * Nothing moves before the path is validated: a refusal leaves the mode,
     * the current file and the current folder as they were. setMode() and
     * setDir() both drop the current file, so the mode goes first, the
     * target next.
     *
     * @throws PathNotFoundException
     */
    public function openPath(string $path): OpenedPath
    {
        if (is_dir($path)) {
            $realPath = $this->pathPolicy->list($path);

            $this->editorState->setMode(EditorMode::Dir);
            $this->editorState->setDir($realPath);

            return new OpenedPath($realPath, EditorMode::Dir);
        }

        $document = $this->documentStore->read($path);

        $this->editorState->setMode(EditorMode::Single);
        $this->editorState->setFile($document->path);

        return new OpenedPath($document->path, EditorMode::Single);
    }
}
