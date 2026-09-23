<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Service\Document\DocumentCodec;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The transformations between a document as the editor holds it and as it
 * sits on disk (lot 03-services-document.md, image handling revised by lot
 * 05-markdown.md): image paths converted, the file's own byte shape (BOM,
 * line endings) reapplied. <br> is no longer touched here — removing
 * remarkPreserveEmptyLinePlugin (FIL-01) means the editor doesn't produce it
 * for an empty paragraph any more, so a <br> found in the text is always one
 * the user actually wrote.
 */
final class DocumentCodecTest extends KernelTestCase
{
    private DocumentCodec $codec;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->codec = self::getContainer()->get(DocumentCodec::class);
    }

    public function testToDiskLeavesABrTagInPlainTextIntact(): void
    {
        self::assertSame('a<br>b', $this->codec->toDisk('a<br>b', null));
    }

    public function testToDiskLeavesABrTagInsideInlineCodeIntact(): void
    {
        self::assertSame('`<br>`', $this->codec->toDisk('`<br>`', null));
    }

    public function testToDiskLeavesABrTagInsideAFencedCodeBlockIntact(): void
    {
        $markdown = "```html\n<br>\n```";

        self::assertSame($markdown, $this->codec->toDisk($markdown, null));
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
     * stray '\' stuck on the recovered path, corrupting it on save. The
     * second query param's value ends in .png too, only so the whole
     * destination still has a recognised image extension.
     */
    public function testToDiskUnescapesBackslashEscapedAmpersand(): void
    {
        $markdown = '![alt](/document/image?path=/home/user/photo.png\&x=other.png)';

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

    /** FIL-08: a raw path already written with a space inside <…> stays that way end to end. */
    public function testRoundTripsAPathWithASpaceAlreadyInAngleBrackets(): void
    {
        $original = '![alt](<./mon image.png>)';

        $editorContent = $this->codec->toEditor($original);

        self::assertSame('./mon image.png', $this->extractServiceUrlPath($editorContent));
        self::assertSame($original, $this->codec->toDisk($editorContent, null));
    }

    /** FIL-08/ARC-04: a bare path with parentheses is written back wrapped in <…>. */
    public function testSavingAPathWithParenthesesWrapsItInAngleBrackets(): void
    {
        $original = '![alt](./a(b).png)';

        $editorContent = $this->codec->toEditor($original);

        self::assertSame('./a(b).png', $this->extractServiceUrlPath($editorContent));
        self::assertSame('![alt](<./a(b).png>)', $this->codec->toDisk($editorContent, null));
    }

    /** ARC-05: %20 is decoded on open, and written back as a literal space inside <…>. */
    public function testAPercentEncodedSpaceIsDecodedThenWrittenAsALiteralSpace(): void
    {
        $original = '![alt](my%20pic.png)';

        $editorContent = $this->codec->toEditor($original);

        self::assertSame('my pic.png', $this->extractServiceUrlPath($editorContent));
        self::assertSame('![alt](<my pic.png>)', $this->codec->toDisk($editorContent, null));
    }

    /** A service URL whose path has a space comes back wrapped in <…>, not with a raw space. */
    public function testAServiceUrlWithASpacedPathComesBackWrappedInAngleBrackets(): void
    {
        $markdown = '![alt](/document/image?path=my%20pic.png)';

        self::assertSame('![alt](<my pic.png>)', $this->codec->toDisk($markdown, null));
    }

    public function testRevisionIsTheXxh128OfTheBytes(): void
    {
        self::assertSame(hash('xxh128', "# Hello\n"), $this->codec->revision("# Hello\n"));
    }

    private function extractServiceUrlPath(string $editorContent): ?string
    {
        preg_match('/\?path=([^)"\s]+)/', $editorContent, $match);

        return isset($match[1]) ? rawurldecode($match[1]) : null;
    }
}
