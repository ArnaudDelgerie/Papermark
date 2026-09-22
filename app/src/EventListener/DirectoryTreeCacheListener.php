<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\Document\DocumentDeleted;
use App\Event\Document\DocumentRenamed;
use App\Event\Document\DocumentSaved;
use App\Service\Directory\OpenDirectoryTree;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Keeps the cached tree walk in step with every write DocumentStore makes. */
final class DirectoryTreeCacheListener
{
    public function __construct(
        private readonly OpenDirectoryTree $openDirectoryTree,
    ) {
    }

    #[AsEventListener]
    public function onDocumentSaved(DocumentSaved $event): void
    {
        $this->openDirectoryTree->fileAdded($event->path);
    }

    #[AsEventListener]
    public function onDocumentDeleted(DocumentDeleted $event): void
    {
        $this->openDirectoryTree->fileRemoved($event->path);
    }

    #[AsEventListener]
    public function onDocumentRenamed(DocumentRenamed $event): void
    {
        $this->openDirectoryTree->fileRenamed($event->oldPath, $event->newPath);
    }
}
