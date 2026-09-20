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

        $result = $this->converter->toServiceUrls($markdown);

        self::assertSame(
            'Text ![alt](/file/image?path=./img/photo.png) more text',
            $result,
        );
    }

    public function testToServiceUrlsLeavesExternalUrlUntouched(): void
    {
        $markdown = '![alt](https://example.com/photo.png)';

        self::assertSame($markdown, $this->converter->toServiceUrls($markdown));
    }

    public function testToServiceUrlsPreservesTitle(): void
    {
        $markdown = '![alt](./photo.png "A title")';

        $result = $this->converter->toServiceUrls($markdown);

        self::assertStringEndsWith(' "A title")', $result);
    }

    public function testToRawPathsRoundTripsWithToServiceUrls(): void
    {
        $original = '![alt](./img/photo.png "caption")';

        $serviceUrls = $this->converter->toServiceUrls($original);
        $rawPaths = $this->converter->toRawPaths($serviceUrls);

        self::assertSame($original, $rawPaths);
    }

    public function testToRawPathsLeavesNonServiceUrlUntouched(): void
    {
        $markdown = '![alt](https://example.com/photo.png)';

        self::assertSame($markdown, $this->converter->toRawPaths($markdown));
    }

    /**
     * Crepe's markdown serializer (remark) backslash-escapes '&' inside a
     * link/image destination — CommonMark's rule for punctuation it finds
     * unsafe there — before this markdown ever reaches PHP. Without
     * unescaping first, parse_str() splits on the literal '&' and leaves a
     * stray '\' stuck on the recovered path, corrupting it on save.
     */
    public function testToRawPathsUnescapesBackslashEscapedAmpersand(): void
    {
        $markdown = '![alt](/file/image?path=/home/user/photo.png\&anchor=/home/user/doc.md)';

        self::assertSame('![alt](/home/user/photo.png)', $this->converter->toRawPaths($markdown));
    }

    /**
     * Documents written before the lot 02 change carry the anchor in the
     * URL: the path comes back from them all the same.
     */
    public function testToRawPathsReadsUrlsFromBeforeTheAnchorRemoval(): void
    {
        $markdown = '![alt](/file/image?path=/home/user/photo.png&anchor=/home/user/doc.md)';

        self::assertSame('![alt](/home/user/photo.png)', $this->converter->toRawPaths($markdown));
    }
}
