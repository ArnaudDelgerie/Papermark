<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Event\Document\DocumentDeleted;
use App\Event\Document\DocumentRenamed;
use App\Event\Document\DocumentSaved;
use App\EventListener\DirectoryTreeCacheListener;
use App\Service\Directory\OpenDirectoryTree;
use App\Service\EditorState;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * OpenDirectoryTree and EditorState are final, so this exercises the real
 * services rather than doubling them (lot 03-services-document.md).
 */
final class DirectoryTreeCacheListenerTest extends KernelTestCase
{
    private string $root;
    private OpenDirectoryTree $openDirectoryTree;
    private DirectoryTreeCacheListener $listener;

    protected function setUp(): void
    {
        self::bootKernel();

        $request = Request::create('http://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);

        $this->root = sys_get_temp_dir() . '/directory_tree_cache_listener_' . uniqid();
        mkdir($this->root);
        file_put_contents($this->root . '/kept.md', '# Kept');

        self::getContainer()->get(EditorState::class)->setDir($this->root);

        $this->openDirectoryTree = self::getContainer()->get(OpenDirectoryTree::class);
        $this->openDirectoryTree->build(); // warms the cache

        $this->listener = new DirectoryTreeCacheListener($this->openDirectoryTree);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDocumentSavedAddsTheFileToTheCachedTree(): void
    {
        $newFile = $this->root . '/new.md';
        file_put_contents($newFile, '# New'); // written outside the app: the cache doesn't know yet

        $this->listener->onDocumentSaved(new DocumentSaved($newFile, 'rev'));

        self::assertSame(['kept.md', 'new.md'], $this->listedFileNames());
    }

    public function testDocumentDeletedRemovesTheFileFromTheCachedTree(): void
    {
        $this->listener->onDocumentDeleted(new DocumentDeleted($this->root . '/kept.md'));

        self::assertSame([], $this->listedFileNames());
    }

    public function testDocumentRenamedUpdatesTheCachedTree(): void
    {
        $this->listener->onDocumentRenamed(new DocumentRenamed($this->root . '/kept.md', $this->root . '/renamed.md'));

        self::assertSame(['renamed.md'], $this->listedFileNames());
    }

    /** @return string[] */
    private function listedFileNames(): array
    {
        $files = $this->openDirectoryTree->build()?->root->files ?? [];
        $names = array_map(static fn ($file) => $file->name, $files);
        sort($names);

        return $names;
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            @unlink($dir . '/' . $item);
        }

        @rmdir($dir);
    }
}
