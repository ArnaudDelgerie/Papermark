<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Service\Document\DocumentCodec;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The transformations between a document as the editor holds it and as it
 * sits on disk (lot 03-services-document.md): <br> stripped, image paths
 * converted, the file's own byte shape (BOM, line endings) reapplied.
 */
final class DocumentCodecTest extends KernelTestCase
{
    private DocumentCodec $codec;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->codec = new DocumentCodec(self::getContainer()->get(UrlGeneratorInterface::class));
    }

    /** The <br> and the newline right after it are stripped together. */
    public function testToDiskStripsBrTagsAndTheFollowingNewline(): void
    {
        self::assertSame('AB', $this->codec->toDisk("A<br>\nB", null));
    }

    public function testToDiskStripsBrTagsWithoutATrailingNewline(): void
    {
        self::assertSame('AB', $this->codec->toDisk('A<br>B', null));
    }

    public function testToDiskStripsASelfClosingBrTag(): void
    {
        self::assertSame('AB', $this->codec->toDisk('A<br/>B', null));
    }

    public function testToDiskConvertsServiceUrlsBackToRawPaths(): void
    {
        $markdown = '![alt](/document/image?path=./photo.png)';

        self::assertSame('![alt](./photo.png)', $this->codec->toDisk($markdown, null));
    }

    /** The image URL is recognized from its route, not from a written prefix. */
    public function testToDiskRecognizesTheImageUrlUnderABaseUrl(): void
    {
        self::getContainer()->get(UrlGeneratorInterface::class)->getContext()->setBaseUrl('/app');

        $editor = $this->codec->toEditor('![alt](./photo.png)');

        self::assertSame('![alt](/app/document/image?path=./photo.png)', $editor);
        self::assertSame('![alt](./photo.png)', $this->codec->toDisk($editor, null));
    }

    public function testToDiskLeavesNonServiceUrlUntouched(): void
    {
        $markdown = '![alt](https://example.com/photo.png)';

        self::assertSame($markdown, $this->codec->toDisk($markdown, null));
    }

    /**
     * Crepe's markdown serializer (remark) backslash-escapes '&' inside a
     * link/image destination — CommonMark's rule for punctuation it finds
     * unsafe there — before this markdown ever reaches PHP. Without
     * unescaping first, parse_str() splits on the literal '&' and leaves a
     * stray '\' stuck on the recovered path, corrupting it on save.
     */
    public function testToDiskUnescapesBackslashEscapedAmpersand(): void
    {
        $markdown = '![alt](/document/image?path=/home/user/photo.png\&anchor=/home/user/doc.md)';

        self::assertSame('![alt](/home/user/photo.png)', $this->codec->toDisk($markdown, null));
    }

    public function testToDiskPutsBackTheBomAndCrLfOfTheDisk(): void
    {
        $disk = "\xEF\xBB\xBF# A\r\n# B\r\n";

        self::assertSame(
            "\xEF\xBB\xBF# A\r\n# B, edited\r\n",
            $this->codec->toDisk("# A\n# B, edited\n", $disk),
        );
    }

    public function testToDiskAppliesNoShapeForANewFile(): void
    {
        self::assertSame("# A\n", $this->codec->toDisk("# A\n", null));
    }

    public function testToDiskNormalizesTheContentFirst(): void
    {
        // A BOM of its own, CRs of its own: the disk's shape is the only one that counts.
        self::assertSame("# A\n", $this->codec->toDisk("\xEF\xBB\xBF# A\r\n", "# old\n"));
    }

    public function testToDiskCrlfOnlyWhenItIsTheDominantLineEnding(): void
    {
        // One of each: CRLF is not dominant, the content keeps its LF.
        self::assertSame("a\nb\n", $this->codec->toDisk("a\nb\n", "a\nb\r\n"));
        self::assertSame("a\nb\n", $this->codec->toDisk("a\nb\n", "a\r\nb\n"));
    }

    public function testToDiskAnEmptyDiskFileHasNoShapeToCopy(): void
    {
        self::assertSame("# A\n", $this->codec->toDisk("# A\n", ''));
    }

    public function testToEditorRewritesLocalImagePath(): void
    {
        $markdown = 'Text ![alt](./img/photo.png) more text';

        self::assertSame(
            'Text ![alt](/document/image?path=./img/photo.png) more text',
            $this->codec->toEditor($markdown),
        );
    }

    public function testToEditorLeavesExternalUrlUntouched(): void
    {
        $markdown = '![alt](https://example.com/photo.png)';

        self::assertSame($markdown, $this->codec->toEditor($markdown));
    }

    public function testToEditorPreservesTitle(): void
    {
        $markdown = '![alt](./photo.png "A title")';

        self::assertStringEndsWith(' "A title")', $this->codec->toEditor($markdown));
    }

    public function testRoundTripsBetweenToEditorAndToDisk(): void
    {
        $original = '![alt](./img/photo.png "caption")';

        $editorContent = $this->codec->toEditor($original);

        self::assertSame($original, $this->codec->toDisk($editorContent, null));
    }

    public function testRevisionIsTheXxh128OfTheBytes(): void
    {
        self::assertSame(hash('xxh128', "# Hello\n"), $this->codec->revision("# Hello\n"));
    }
}
