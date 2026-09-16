<?php

declare(strict_types=1);

namespace App\Tests\File;

use App\File\MarkdownFileReader;
use App\File\MarkdownImageUrls;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MarkdownFileReaderTest extends KernelTestCase
{
    private MarkdownFileReader $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->reader = new MarkdownFileReader(
            new MarkdownImageUrls(self::getContainer()->get(UrlGeneratorInterface::class)),
        );
    }

    public function testReadsAndConvertsImagePaths(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '![alt](./photo.png)');

        $content = $this->reader->read($path);

        self::assertSame('![alt](/file/image?path=./photo.png&anchor=' . $path . ')', $content);

        unlink($path);
    }

    public function testReturnsNullForMissingFile(): void
    {
        self::assertNull($this->reader->read('/tmp/this_file_does_not_exist_98765.md'));
    }

    public function testReturnsNullForUnsupportedExtension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test_') . '.exe';
        file_put_contents($path, 'content');

        self::assertNull($this->reader->read($path));

        unlink($path);
    }
}
