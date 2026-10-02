<?php

declare(strict_types=1);

namespace App\Tests\Service\Directory;

use App\Service\Directory\DirectoryTree;
use PHPUnit\Framework\TestCase;

final class DirectoryTreeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/directory_tree_test_' . uniqid();
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testOnlyDocumentsAreListedAndOnlyDirsHoldingOneAppear(): void
    {
        mkdir($this->root . '/docs');
        mkdir($this->root . '/docs/nested');
        mkdir($this->root . '/images_only');
        file_put_contents($this->root . '/top.md', '#');
        file_put_contents($this->root . '/top.pdf', 'ignored');
        file_put_contents($this->root . '/docs/nested/deep.md', '#');
        file_put_contents($this->root . '/images_only/logo.png', 'ignored');

        $result = (new DirectoryTree())->build($this->root);

        self::assertFalse($result->tooLarge);
        $root = $result->root;

        self::assertSame(['top.md'], array_map(static fn ($f) => $f->name, $root->files));
        self::assertSame(['docs'], array_map(static fn ($d) => $d->name, $root->directories));

        $docs = $root->directories[0];
        self::assertSame([], $docs->files);
        self::assertSame(['nested'], array_map(static fn ($d) => $d->name, $docs->directories));
        self::assertSame(['deep.md'], array_map(static fn ($f) => $f->name, $docs->directories[0]->files));
    }

    /** DocumentExtension covers .md, .markdown and .txt (lot 03-services-document.md). */
    public function testMarkdownAndTxtFilesAreListedToo(): void
    {
        file_put_contents($this->root . '/notes.markdown', '#');
        file_put_contents($this->root . '/readme.txt', 'text');

        $result = (new DirectoryTree())->build($this->root);

        $names = array_map(static fn ($f) => $f->name, $result->root->files);
        sort($names);
        self::assertSame(['notes.markdown', 'readme.txt'], $names);
    }

    public function testHiddenFilesAndDirectoriesAreIncluded(): void
    {
        mkdir($this->root . '/.hidden');
        file_put_contents($this->root . '/.hidden/secret.md', '#');

        $result = (new DirectoryTree())->build($this->root);

        self::assertSame(['.hidden'], array_map(static fn ($d) => $d->name, $result->root->directories));
    }

    /**
     * A linked .md stays listed — the file exists, the user looks for it —
     * but flagged, so the tree can tell it apart and disable its actions
     * (lot 02-chemins.md, SEC-02).
     */
    public function testListsASymlinkedMarkdownFileFlaggedAsOne(): void
    {
        $target = tempnam(sys_get_temp_dir(), 'target_') . '.md';
        file_put_contents($target, '# Target');
        symlink($target, $this->root . '/linked.md');

        $result = (new DirectoryTree())->build($this->root);

        self::assertSame(['linked.md'], array_map(static fn ($f) => $f->name, $result->root->files));
        self::assertTrue($result->root->files[0]->symlink);
        self::assertSame($this->root . '/linked.md', $result->root->files[0]->path);

        unlink($this->root . '/linked.md');
        unlink($target);
    }

    /**
     * A .gitignore describes what to commit, not what's noise — a file
     * matching it is still real content and still counts (see EDITOR_FOLDER_MODE.md).
     */
    public function testGitignoredFilesAndDirectoriesAreStillIncluded(): void
    {
        file_put_contents($this->root . '/.gitignore', "ignored_dir/\n");
        mkdir($this->root . '/ignored_dir');
        file_put_contents($this->root . '/ignored_dir/notes.md', '#');

        $result = (new DirectoryTree())->build($this->root);

        self::assertSame(['ignored_dir'], array_map(static fn ($d) => $d->name, $result->root->directories));
        self::assertSame(['notes.md'], array_map(static fn ($f) => $f->name, $result->root->directories[0]->files));
    }

    /**
     * PHP casts a decimal-shaped array key to int ('2026' becomes 2026),
     * and strict_types then rejects it as a string in buildNode() — one
     * such directory made the whole tree fail (lot 06-dossier-numerique.md).
     */
    public function testAWhollyNumericDirectoryNameBuildsLikeAnyOther(): void
    {
        mkdir($this->root . '/journal');
        mkdir($this->root . '/journal/2026');
        mkdir($this->root . '/01');
        file_put_contents($this->root . '/journal/2026/day.md', '#');
        file_put_contents($this->root . '/01/note.md', '#');

        $result = (new DirectoryTree())->build($this->root);

        self::assertFalse($result->tooLarge);
        $root = $result->root;

        $byName = [];
        foreach ($root->directories as $directory) {
            $byName[$directory->name] = $directory;
        }

        self::assertSame('01', $byName['01']->name);
        self::assertSame('01', basename($byName['01']->path));

        $journal = $byName['journal'];
        self::assertSame('2026', $journal->directories[0]->name);
        self::assertSame('2026', basename($journal->directories[0]->path));
    }

    public function testStopsAndReportsTooLargeWhenTraversalCapIsExceeded(): void
    {
        file_put_contents($this->root . '/a.md', 'a');
        file_put_contents($this->root . '/b.md', 'b');

        $result = (new DirectoryTree(maxItems: 1))->build($this->root);

        self::assertTrue($result->tooLarge);
        self::assertNull($result->root);
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $path . '/' . $item;
            is_dir($itemPath) ? $this->removeDirectory($itemPath) : unlink($itemPath);
        }
        rmdir($path);
    }
}
