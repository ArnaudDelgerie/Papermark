<?php

declare(strict_types=1);

namespace App\Tests\File;

use App\Exception\File\RenameTargetExistsException;
use App\Exception\File\WriteFailedException;
use App\File\NoReplaceRename;
use PHPUnit\Framework\TestCase;

final class NoReplaceRenameTest extends TestCase
{
    private string $root;
    private NoReplaceRename $renamer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/no_replace_rename_test_' . uniqid();
        mkdir($this->root);
        $this->renamer = new NoReplaceRename();
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->root) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            @unlink($this->root . '/' . $item);
        }

        @rmdir($this->root);
    }

    public function testRenamesWhenTheTargetIsFree(): void
    {
        $source = $this->createFile('a.md', '# A');

        $this->renamer->rename($source, $this->root . '/b.md');

        self::assertFileDoesNotExist($source);
        self::assertSame('# A', file_get_contents($this->root . '/b.md'));
    }

    /**
     * The FIL-06 case: the target appears between any existence check and
     * the move. The primitive has no such window — link() refuses an
     * existing target atomically — so an existing target is the same race,
     * answered the same way.
     */
    public function testRefusesToReplaceAnExistingTarget(): void
    {
        $source = $this->createFile('a.md', '# Source');
        $target = $this->createFile('b.md', '# Taken');

        try {
            $this->renamer->rename($source, $target);
            self::fail('The rename should have been refused.');
        } catch (RenameTargetExistsException) {
        }

        self::assertSame('# Source', file_get_contents($source));
        self::assertSame('# Taken', file_get_contents($target));
    }

    /** A dangling link is a taken name too: file_exists() alone wouldn't see it. */
    public function testRefusesToReplaceADanglingLinkTarget(): void
    {
        $source = $this->createFile('a.md', '# Source');
        $target = $this->root . '/b.md';
        symlink($this->root . '/nowhere.md', $target);

        try {
            $this->renamer->rename($source, $target);
            self::fail('The rename should have been refused.');
        } catch (RenameTargetExistsException) {
        }

        self::assertFileExists($source);
        self::assertTrue(is_link($target));
    }

    public function testReportsFailureForAMissingSource(): void
    {
        $this->expectException(WriteFailedException::class);

        $this->renamer->rename($this->root . '/no_such_file.md', $this->root . '/b.md');
    }

    private function createFile(string $name, string $content): string
    {
        $path = $this->root . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }
}
