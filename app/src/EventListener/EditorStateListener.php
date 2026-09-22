<?php

declare(strict_types=1);

namespace App\EventListener;

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
}
