<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Enum\Setting\EditorMode;
use App\Event\ArchiveImported;
use App\Event\Document\DocumentDeleted;
use App\Event\Document\DocumentRenamed;
use App\Event\Document\DocumentSaved;
use App\EventListener\EditorStateListener;
use App\Service\Editor\EditorState;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class EditorStateListenerTest extends KernelTestCase
{
    private EditorState $editorState;
    private EditorStateListener $listener;

    protected function setUp(): void
    {
        self::bootKernel();

        $request = Request::create('http://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);

        $this->editorState = self::getContainer()->get(EditorState::class);
        $this->listener = new EditorStateListener($this->editorState);
    }

    /** A plain save or a Save as: either way, the saved file becomes current. */
    public function testDocumentSavedMakesTheFileTheCurrentOne(): void
    {
        $this->listener->onDocumentSaved(new DocumentSaved('/a/doc.md', 'rev'));

        self::assertSame('/a/doc.md', $this->editorState->getFile());
    }

    public function testDocumentDeletedClearsTheCurrentFileWhenItIsTheOneDeleted(): void
    {
        $this->editorState->setFile('/a/doc.md');

        $this->listener->onDocumentDeleted(new DocumentDeleted('/a/doc.md'));

        self::assertNull($this->editorState->getFile());
    }

    public function testDocumentDeletedLeavesAnUnrelatedCurrentFileAlone(): void
    {
        $this->editorState->setFile('/a/kept.md');

        $this->listener->onDocumentDeleted(new DocumentDeleted('/a/other.md'));

        self::assertSame('/a/kept.md', $this->editorState->getFile());
    }

    public function testDocumentRenamedUpdatesTheCurrentFileWhenItIsTheOneRenamed(): void
    {
        $this->editorState->setFile('/a/old.md');

        $this->listener->onDocumentRenamed(new DocumentRenamed('/a/old.md', '/a/new.md'));

        self::assertSame('/a/new.md', $this->editorState->getFile());
    }

    public function testDocumentRenamedLeavesAnUnrelatedCurrentFileAlone(): void
    {
        $this->editorState->setFile('/a/kept.md');

        $this->listener->onDocumentRenamed(new DocumentRenamed('/a/old.md', '/a/new.md'));

        self::assertSame('/a/kept.md', $this->editorState->getFile());
    }

    public function testArchiveImportedOpeningAFileSwitchesToSingleMode(): void
    {
        $this->editorState->setMode(EditorMode::Dir);

        $this->listener->onArchiveImported(new ArchiveImported('/dest/notes', EditorMode::Single, '/dest/notes/doc.md'));

        self::assertSame(EditorMode::Single, $this->editorState->getMode());
        self::assertSame('/dest/notes/doc.md', $this->editorState->getFile());
    }

    public function testArchiveImportedOpeningAFolderSwitchesToDirMode(): void
    {
        $this->editorState->setMode(EditorMode::Single);

        $this->listener->onArchiveImported(new ArchiveImported('/dest/project', EditorMode::Dir, null));

        self::assertSame(EditorMode::Dir, $this->editorState->getMode());
        self::assertSame('/dest/project', $this->editorState->getDir());
    }

    public function testArchiveImportedWithNothingToOpenLeavesTheStateAsItWas(): void
    {
        $this->editorState->setMode(EditorMode::Single);
        $this->editorState->setFile('/a/kept.md');

        $this->listener->onArchiveImported(new ArchiveImported('/dest/pdfs', null, null));

        self::assertSame(EditorMode::Single, $this->editorState->getMode());
        self::assertSame('/a/kept.md', $this->editorState->getFile());
    }
}
