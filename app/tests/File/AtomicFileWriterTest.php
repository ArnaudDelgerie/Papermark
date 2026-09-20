<?php

declare(strict_types=1);

namespace App\Tests\File;

use App\File\AtomicFileWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;

/**
 * The service that closes FIL-02 and HUB-04 (lot 03-enregistrement.md): the
 * file is written whole or not at all, published by rename().
 */
final class AtomicFileWriterTest extends TestCase
{
    private AtomicFileWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new AtomicFileWriter();
    }

    private function tempPath(string $prefix): string
    {
        $temp = tempnam(sys_get_temp_dir(), $prefix . '_');
        $path = $temp . '.md';
        unlink($temp);

        return $path;
    }

    public function testWritesTheContentAndLeavesNoTemporaryBehind(): void
    {
        $path = $this->tempPath('atomic_new');

        $this->writer->write($path, "# Hello\n");

        self::assertSame("# Hello\n", file_get_contents($path));
        self::assertSame([], glob(\dirname($path) . '/.papermark-save-*'));

        unlink($path);
    }

    public function testKeepsThePermissionsOfTheExistingFile(): void
    {
        $path = $this->tempPath('atomic_perms');
        file_put_contents($path, 'old');
        chmod($path, 0640);
        $permissions = fileperms($path) & 07777;

        $this->writer->write($path, 'new');

        self::assertSame($permissions, fileperms($path) & 07777);

        unlink($path);
    }

    /** Publishing by rename makes the file ours: a new inode, not the old one. */
    public function testPublishesByRenameNotInPlace(): void
    {
        $path = $this->tempPath('atomic_inode');
        file_put_contents($path, 'old');
        $inode = fileinode($path);

        $this->writer->write($path, 'new');

        self::assertNotSame($inode, fileinode($path));

        unlink($path);
    }

    /**
     * The short write (disk full): the byte-count check turns it into an
     * error, the original stays exactly as it was, and the temporary leaves
     * no trace.
     */
    public function testAShortWriteFailsAndLeavesTheOriginalIntact(): void
    {
        $path = $this->tempPath('atomic_short');
        file_put_contents($path, 'original');

        try {
            $this->writer->write($path, 'replacement', expectedLength: 100);
            self::fail('A short write must fail.');
        } catch (IOException) {
        }

        self::assertSame('original', file_get_contents($path));
        self::assertSame([], glob(\dirname($path) . '/.papermark-save-*'));

        unlink($path);
    }

    /** Same expectations when nothing is on disk yet: the file must not appear. */
    public function testAShortWriteOnANewFileLeavesNoFile(): void
    {
        $path = $this->tempPath('atomic_short_new');

        try {
            $this->writer->write($path, 'replacement', expectedLength: 100);
            self::fail('A short write must fail.');
        } catch (IOException) {
        }

        self::assertFileDoesNotExist($path);
        self::assertSame([], glob(\dirname($path) . '/.papermark-save-*'));
    }
}
