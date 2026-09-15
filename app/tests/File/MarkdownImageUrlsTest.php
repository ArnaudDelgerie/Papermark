<?php

declare(strict_types=1);

namespace App\Tests\File;

use App\File\MarkdownImageUrls;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MarkdownImageUrlsTest extends KernelTestCase
{
    private MarkdownImageUrls $converter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->converter = new MarkdownImageUrls(self::getContainer()->get(UrlGeneratorInterface::class));
    }

    public function testToServiceUrlsRewritesLocalImagePath(): void
    {
        $markdown = 'Text ![alt](./img/photo.png) more text';

        $result = $this->converter->toServiceUrls($markdown, '/home/user/doc.md');

        self::assertSame(
            'Text ![alt](/file/image?path=./img/photo.png&anchor=/home/user/doc.md) more text',
            $result,
        );
    }

    public function testToServiceUrlsLeavesExternalUrlUntouched(): void
    {
        $markdown = '![alt](https://example.com/photo.png)';

        self::assertSame($markdown, $this->converter->toServiceUrls($markdown, '/home/user/doc.md'));
    }

    public function testToServiceUrlsPreservesTitle(): void
    {
        $markdown = '![alt](./photo.png "A title")';

        $result = $this->converter->toServiceUrls($markdown, '/home/user/doc.md');

        self::assertStringEndsWith(' "A title")', $result);
    }

    public function testToRawPathsRoundTripsWithToServiceUrls(): void
    {
        $original = '![alt](./img/photo.png "caption")';

        $serviceUrls = $this->converter->toServiceUrls($original, '/home/user/doc.md');
        $rawPaths = $this->converter->toRawPaths($serviceUrls);

        self::assertSame($original, $rawPaths);
    }

    public function testToRawPathsLeavesNonServiceUrlUntouched(): void
    {
        $markdown = '![alt](https://example.com/photo.png)';

        self::assertSame($markdown, $this->converter->toRawPaths($markdown));
    }
}
