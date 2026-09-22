<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\Filesystem\DeleteFailedException;
use App\Exception\Filesystem\RenameTargetExistsException;
use App\Exception\Filesystem\WriteFailedException;
use App\Service\SafeFilesystem;
use PHPUnit\Framework\TestCase;

/**
 * The disk, tout ou rien (lot 03-services-document.md): write, rename and
 * delete each succeed completely or leave the target untouched.
 */
final class SafeFilesystemTest extends TestCase
{
    private string $root;
    private SafeFilesystem $filesystem;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/safe_filesystem_test_' . uniqid();
        mkdir($this->root);
        $this->filesystem = new SafeFilesystem();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testWritesTheContentAndLeavesNoTemporaryBehind(): void
    {
        $path = $this->root . '/new.md';

        $this->filesystem->write($path, "# Hello\n");

        self::assertSame("# Hello\n", file_get_contents($path));
        self::assertSame([], glob($this->root . '/.papermark-save-*'));
    }

    public function testKeepsThePermissionsOfTheExistingFile(): void
    {
        $path = $this->createFile('a.md', 'old');
        chmod($path, 0640);
        $permissions = fileperms($path) & 07777;

        $this->filesystem->write($path, 'new');

        self::assertSame($permissions, fileperms($path) & 07777);
    }

    /** Publishing by rename makes the file ours: a new inode, not the old one. */
    public function testPublishesByRenameNotInPlace(): void
    {
        $path = $this->createFile('a.md', 'old');
        $inode = fileinode($path);

        $this->filesystem->write($path, 'new');

        self::assertNotSame($inode, fileinode($path));
    }

    /**
     * The short write (disk full): the byte-count check turns it into an
     * error, the original stays exactly as it was, and the temporary leaves
     * no trace.
     */
    public function testAShortWriteFailsAndLeavesTheOriginalIntact(): void
    {
        $path = $this->createFile('a.md', 'original');

        try {
            $this->filesystem->write($path, 'replacement', expectedLength: 100);
            self::fail('A short write must fail.');
        } catch (WriteFailedException) {
        }

        self::assertSame('original', file_get_contents($path));
        self::assertSame([], glob($this->root . '/.papermark-save-*'));
    }

    /** Same expectations when nothing is on disk yet: the file must not appear. */
    public function testAShortWriteOnANewFileLeavesNoFile(): void
    {
        $path = $this->root . '/new.md';

        try {
            $this->filesystem->write($path, 'replacement', expectedLength: 100);
            self::fail('A short write must fail.');
        } catch (WriteFailedException) {
        }

        self::assertFileDoesNotExist($path);
        self::assertSame([], glob($this->root . '/.papermark-save-*'));
    }

    public function testRenamesWhenTheTargetIsFree(): void
    {
        $source = $this->createFile('a.md', '# A');

        $this->filesystem->rename($source, $this->root . '/b.md');

        self::assertFileDoesNotExist($source);
        self::assertSame('# A', file_get_contents($this->root . '/b.md'));
    }

    /**
     * The FIL-06 case: the target appears between any existence check and
     * the move. The primitive has no such window — link() refuses an
     * existing target atomically — so an existing target is the same race,
     * answered the same way.
     */
    public function testRenameRefusesToReplaceAnExistingTarget(): void
    {
        $source = $this->createFile('a.md', '# Source');
        $target = $this->createFile('b.md', '# Taken');

        try {
            $this->filesystem->rename($source, $target);
            self::fail('The rename should have been refused.');
        } catch (RenameTargetExistsException) {
        }

        self::assertSame('# Source', file_get_contents($source));
        self::assertSame('# Taken', file_get_contents($target));
    }

    /** A dangling link is a taken name too: file_exists() alone wouldn't see it. */
    public function testRenameRefusesToReplaceADanglingLinkTarget(): void
    {
        $source = $this->createFile('a.md', '# Source');
        $target = $this->root . '/b.md';
        symlink($this->root . '/nowhere.md', $target);

        try {
            $this->filesystem->rename($source, $target);
            self::fail('The rename should have been refused.');
        } catch (RenameTargetExistsException) {
        }

        self::assertFileExists($source);
        self::assertTrue(is_link($target));
    }

    public function testRenameReportsFailureForAMissingSource(): void
    {
        $this->expectException(WriteFailedException::class);

        $this->filesystem->rename($this->root . '/no_such_file.md', $this->root . '/b.md');
    }

    public function testDeleteRemovesTheFile(): void
    {
        $path = $this->createFile('a.md', '# Hello');

        $this->filesystem->delete($path);

        self::assertFileDoesNotExist($path);
    }

    public function testDeleteReportsFailureForAMissingFile(): void
    {
        $this->expectException(DeleteFailedException::class);

        $this->filesystem->delete($this->root . '/missing.md');
    }

    private function createFile(string $name, string $content): string
    {
        $path = $this->root . '/' . $name;
        file_put_contents($path, $content);

        return $path;
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
