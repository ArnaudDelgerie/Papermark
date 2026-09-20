<?php

declare(strict_types=1);

namespace App\Tests\File;

use App\File\MarkdownFileReader;
use PHPUnit\Framework\TestCase;

final class MarkdownFileReaderTest extends TestCase
{
    private MarkdownFileReader $reader;

    protected function setUp(): void
    {
        $this->reader = new MarkdownFileReader();
    }

    public function testReadsRawBytesWithBomAndLineEndings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, "\xEF\xBB\xBF# Hello\r\n");

        self::assertSame("\xEF\xBB\xBF# Hello\r\n", $this->reader->readRaw($path));

        unlink($path);
    }

    /** An empty file is a legitimate "": only a failed read is null. */
    public function testAnEmptyFileIsAnEmptyString(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '');

        self::assertSame('', $this->reader->readRaw($path));

        unlink($path);
    }

    public function testReturnsNullForMissingFile(): void
    {
        self::assertNull($this->reader->readRaw('/tmp/this_file_does_not_exist_98765.md'));
    }

    public function testReturnsNullForUnsupportedExtension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_') . '.exe';
        file_put_contents($path, 'content');

        self::assertNull($this->reader->readRaw($path));

        unlink($path);
    }
}
