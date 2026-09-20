<?php

declare(strict_types=1);

namespace App\Tests\File;

use App\File\DiskFormat;
use PHPUnit\Framework\TestCase;

/**
 * The byte-level shape of a file — BOM, dominant line endings — reapplied to
 * new content, so a Windows file stays a Windows file (lot 03, FIL-11).
 */
final class DiskFormatTest extends TestCase
{
    private DiskFormat $format;

    protected function setUp(): void
    {
        $this->format = new DiskFormat();
    }

    public function testPutsBackTheBomAndCrLfOfTheDisk(): void
    {
        $disk = "\xEF\xBB\xBF# A\r\n# B\r\n";

        self::assertSame(
            "\xEF\xBB\xBF# A\r\n# B, edited\r\n",
            $this->format->apply("# A\n# B, edited\n", $disk),
        );
    }

    public function testLeavesAnLfFileWithoutBomAsItIs(): void
    {
        self::assertSame("# A\n# B\n", $this->format->apply("# A\n# B\n", "# old\n"));
    }

    public function testNormalizesTheContentFirst(): void
    {
        // A BOM of its own, CRs of its own: the disk's shape is the only one that counts.
        self::assertSame("# A\n", $this->format->apply("\xEF\xBB\xBF# A\r\n", "# old\n"));
    }

    public function testCrlfOnlyWhenItIsTheDominantLineEnding(): void
    {
        // One of each: CRLF is not dominant, the content keeps its LF.
        self::assertSame("a\nb\n", $this->format->apply("a\nb\n", "a\nb\r\n"));
        self::assertSame("a\nb\n", $this->format->apply("a\nb\n", "a\r\nb\n"));
    }

    public function testAnEmptyDiskFileHasNoShapeToCopy(): void
    {
        self::assertSame("# A\n", $this->format->apply("# A\n", ''));
    }
}
