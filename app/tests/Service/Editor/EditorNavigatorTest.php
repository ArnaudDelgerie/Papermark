<?php

declare(strict_types=1);

namespace App\Tests\Service\Editor;

use App\Enum\Setting\EditorMode;
use App\Exception\Document\DocumentNotUtf8Exception;
use App\Exception\Path\PathNotFoundException;
use App\Service\Directory\OpenDirectoryTree;
use App\Service\Editor\EditorNavigator;
use App\Service\Editor\EditorState;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * EditorNavigator (lot 05-editor-navigator.md): the operation service behind
 * the routes that write the EditorState. EditorState, DocumentStore,
 * PathPolicy and OpenDirectoryTree are all final, so this exercises the real
 * services rather than doubling them (as DirectoryTreeCacheListenerTest does).
 */
final class EditorNavigatorTest extends KernelTestCase
{
    private string $root;
    private EditorState $editorState;
    private OpenDirectoryTree $openDirectoryTree;
    private EditorNavigator $navigator;

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $request = Request::create('http://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);

        $this->root = sys_get_temp_dir() . '/editor_navigator_test_' . uniqid();
        mkdir($this->root);

        $this->editorState = self::getContainer()->get(EditorState::class);
        $this->openDirectoryTree = self::getContainer()->get(OpenDirectoryTree::class);
        $this->navigator = self::getContainer()->get(EditorNavigator::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }
        $this->removeDirectory($this->root);
    }

    public function testSetModeRecordsIt(): void
    {
        $this->navigator->setMode(EditorMode::Dir);

        self::assertSame(EditorMode::Dir, $this->editorState->getMode());
    }

    public function testOpenFileMakesItCurrentAndReturnsTheRealPath(): void
    {
        $path = $this->createFile('doc.md', '# Hello');

        $realPath = $this->navigator->openFile($path);

        self::assertSame($path, $realPath);
        self::assertSame($path, $this->editorState->getFile());
    }

    public function testOpenFileDropsTheCurrentFileOnlyWhenItIsTheOneNotFound(): void
    {
        $kept = $this->createFile('kept.md', '# Kept');
        $this->navigator->openFile($kept);

        try {
            $this->navigator->openFile($this->root . '/missing.md');
            self::fail('Expected PathNotFoundException.');
        } catch (PathNotFoundException) {
        }

        // A bad path picked by hand leaves an unrelated current file alone.
        self::assertSame($kept, $this->editorState->getFile());

        unlink($kept);
        try {
            $this->navigator->openFile($kept);
            self::fail('Expected PathNotFoundException.');
        } catch (PathNotFoundException) {
        }

        // The current file found gone is dropped.
        self::assertNull($this->editorState->getFile());
    }

    public function testOpenFileRefusesANonUtf8FileWithoutTouchingTheState(): void
    {
        $kept = $this->createFile('kept.md', '# Kept');
        $this->navigator->openFile($kept);

        $bad = $this->root . '/bad.md';
        file_put_contents($bad, "Coucou \xE9\xE8.txt");
        $this->tempPaths[] = $bad;

        $this->expectException(DocumentNotUtf8Exception::class);

        try {
            $this->navigator->openFile($bad);
        } finally {
            self::assertSame($kept, $this->editorState->getFile());
        }
    }

    public function testCloseFileLeavesNoCurrentFile(): void
    {
        $this->navigator->openFile($this->createFile('doc.md', '# Hello'));

        $this->navigator->closeFile();

        self::assertNull($this->editorState->getFile());
    }

    public function testOpenDirSetsTheCurrentFolderAndDropsTheCurrentFile(): void
    {
        $this->navigator->openFile($this->createFile('doc.md', '# Hello'));

        $realPath = $this->navigator->openDir($this->root);

        self::assertSame($this->root, $realPath);
        self::assertSame($this->root, $this->editorState->getDir());
        self::assertNull($this->editorState->getFile());
    }

    public function testOpenDirRefusesAPathThatIsNotADirectory(): void
    {
        $this->expectException(PathNotFoundException::class);

        $this->navigator->openDir($this->root . '/missing');
    }

    /** Re-selecting the same folder must not throw away the cached walk. */
    public function testOpenDirTwiceOnTheSameFolderDoesNotRedoTheWalk(): void
    {
        file_put_contents($this->root . '/first.md', '# First');
        $this->navigator->openDir($this->root);
        $this->openDirectoryTree->build(); // warms the cache

        file_put_contents($this->root . '/second.md', '# Second'); // written outside the app

        $this->navigator->openDir($this->root);

        self::assertSame(['first.md'], $this->listedFileNames());
    }

    public function testRefreshDirRedoesTheWalk(): void
    {
        file_put_contents($this->root . '/first.md', '# First');
        $this->navigator->openDir($this->root);
        $this->openDirectoryTree->build(); // warms the cache

        file_put_contents($this->root . '/second.md', '# Second'); // written outside the app

        $this->navigator->refreshDir();

        self::assertSame(['first.md', 'second.md'], $this->listedFileNames());
    }

    /** @return string[] */
    private function listedFileNames(): array
    {
        $files = $this->openDirectoryTree->build()?->root->files ?? [];
        $names = array_map(static fn ($file) => $file->name, $files);
        sort($names);

        return $names;
    }

    private function createFile(string $name, string $content): string
    {
        $path = $this->root . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            @unlink($dir . '/' . $item);
        }

        @rmdir($dir);
    }
}
