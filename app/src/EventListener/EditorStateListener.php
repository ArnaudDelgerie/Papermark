<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Enum\Setting\EditorMode;
use App\Event\ArchiveImported;
use App\Event\Document\DocumentDeleted;
use App\Event\Document\DocumentRenamed;
use App\Event\Document\DocumentSaved;
use App\Service\Editor\EditorState;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps the current file in step with every write DocumentStore makes: a
 * saved file becomes the current one (plain save or Save as, see
 * EDITOR_REACTIVITY.md); the current file follows its own rename, and
 * disappears with its own deletion.
 */
final class EditorStateListener
{
    public function __construct(
        private readonly EditorState $editorState,
    ) {
    }

    #[AsEventListener]
    public function onDocumentSaved(DocumentSaved $event): void
    {
        $this->editorState->setFile($event->path);
    }

    #[AsEventListener]
    public function onDocumentDeleted(DocumentDeleted $event): void
    {
        if ($this->editorState->getFile() === $event->path) {
            $this->editorState->setFile(null);
        }
    }

    #[AsEventListener]
    public function onDocumentRenamed(DocumentRenamed $event): void
    {
        if ($this->editorState->getFile() === $event->oldPath) {
            $this->editorState->setFile($event->newPath);
        }
    }

    /** The state now points at what the archive gave to open, if anything. */
    #[AsEventListener]
    public function onArchiveImported(ArchiveImported $event): void
    {
        if (EditorMode::Single === $event->openMode) {
            $this->editorState->setMode(EditorMode::Single);
            $this->editorState->setFile($event->openPath);
        } elseif (EditorMode::Dir === $event->openMode) {
            $this->editorState->setMode(EditorMode::Dir);
            $this->editorState->setDir($event->destination);
        }
    }
}
