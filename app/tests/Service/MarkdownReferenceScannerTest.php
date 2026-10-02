<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\MarkdownReference;
use App\Enum\MarkdownReferenceType;
use App\Service\MarkdownReferenceScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownReferenceScannerTest extends TestCase
{
    private MarkdownReferenceScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new MarkdownReferenceScanner();
    }

    public function testFindsLocalImage(): void
    {
        $markdown = 'Text ![alt](./img/photo.png "A title") more';
        $offset = strpos($markdown, './img/photo.png');

        $references = $this->scanner->find($markdown);

        self::assertEquals(
            [new MarkdownReference(MarkdownReferenceType::Image, './img/photo.png', null, $offset, \strlen('./img/photo.png'))],
            $references,
        );
    }

    public function testFindsLocalMarkdownLink(): void
    {
        $markdown = 'See [notes](./notes.md) for details';
        $offset = strpos($markdown, './notes.md');

        $references = $this->scanner->find($markdown);

        self::assertEquals(
            [new MarkdownReference(MarkdownReferenceType::Link, './notes.md', null, $offset, \strlen('./notes.md'))],
            $references,
        );
    }

    #[DataProvider('provideDocumentExtensions')]
    public function testFindsEachRecognisedLinkExtension(string $extension): void
    {
        $references = $this->scanner->find("[doc](./file.{$extension})");

        self::assertCount(1, $references);
        self::assertSame("./file.{$extension}", $references[0]->path);
    }

    /** @return iterable<array{0: string}> */
    public static function provideDocumentExtensions(): iterable
    {
        yield ['md'];
        yield ['markdown'];
        yield ['txt'];
    }

    public function testSplitsAnchorFromLinkPath(): void
    {
        $references = $this->scanner->find('[intro](notes.md#intro)');

        self::assertSame('notes.md', $references[0]->path);
        self::assertSame('#intro', $references[0]->fragment);
    }

    public function testIgnoresExternalImageUrl(): void
    {
        self::assertSame([], $this->scanner->find('![alt](https://example.com/photo.png)'));
    }

    public function testIgnoresExternalLinkUrl(): void
    {
        self::assertSame([], $this->scanner->find('[site](https://example.com/page.md)'));
    }

    public function testIgnoresDataUri(): void
    {
        self::assertSame([], $this->scanner->find('![alt](data:image/png;base64,aaaa)'));
    }

    public function testIgnoresHtmlImgTag(): void
    {
        self::assertSame([], $this->scanner->find('<img src="photo.png" alt="alt">'));
    }

    public function testIgnoresLinkWithUnrecognisedExtension(): void
    {
        self::assertSame([], $this->scanner->find('[doc](./report.pdf)'));
    }

    public function testIgnoresImageWithUnrecognisedExtension(): void
    {
        self::assertSame([], $this->scanner->find('![alt](./file.bmp)'));
    }

    public function testDoesNotTreatImageSyntaxToMarkdownAsLink(): void
    {
        self::assertSame([], $this->scanner->find('![alt](./notes.md)'));
    }

    public function testExtensionMatchIsCaseInsensitive(): void
    {
        $references = $this->scanner->find('![alt](./PHOTO.PNG)');

        self::assertCount(1, $references);
        self::assertSame(MarkdownReferenceType::Image, $references[0]->type);
    }

    public function testFindsBothImagesAndLinksInSameDocument(): void
    {
        $references = $this->scanner->find('![alt](./photo.png) and [notes](./notes.md)');

        self::assertCount(2, $references);
        self::assertSame(MarkdownReferenceType::Image, $references[0]->type);
        self::assertSame(MarkdownReferenceType::Link, $references[1]->type);
    }

    public function testFindsTheSameReferenceTwiceAtDistinctPositions(): void
    {
        $markdown = '![a](./photo.png) ![b](./photo.png)';

        $references = $this->scanner->find($markdown);

        self::assertCount(2, $references);
        self::assertSame('./photo.png', $references[0]->path);
        self::assertSame('./photo.png', $references[1]->path);
        self::assertNotSame($references[0]->destinationOffset, $references[1]->destinationOffset);
        self::assertSame('./photo.png', substr($markdown, $references[0]->destinationOffset, $references[0]->destinationLength));
        self::assertSame('./photo.png', substr($markdown, $references[1]->destinationOffset, $references[1]->destinationLength));
    }

    public function testDestinationOffsetExcludesAltAndTitle(): void
    {
        $markdown = '![some alt text](./photo.png "a title")';

        $references = $this->scanner->find($markdown);

        self::assertSame('./photo.png', substr($markdown, $references[0]->destinationOffset, $references[0]->destinationLength));
    }

    public function testReadsARawSpaceInsideAngleBrackets(): void
    {
        $references = $this->scanner->find('![alt](<./my photo.png>)');

        self::assertSame('./my photo.png', $references[0]->path);
    }

    public function testAngleBracketsDestinationIncludesTheBracketsInItsSpan(): void
    {
        $markdown = '![alt](<./my photo.png>)';

        $references = $this->scanner->find($markdown);

        self::assertSame('<./my photo.png>', substr($markdown, $references[0]->destinationOffset, $references[0]->destinationLength));
    }

    public function testReadsBalancedParenthesesInABareDestination(): void
    {
        $references = $this->scanner->find('![alt](./a(b).png)');

        self::assertSame('./a(b).png', $references[0]->path);
    }

    public function testReadsBackslashEscapedParenthesesInABareDestination(): void
    {
        $references = $this->scanner->find('![alt](./a\\(b\\).png)');

        self::assertSame('./a(b).png', $references[0]->path);
    }

    public function testDecodesPercentEncodedCharacters(): void
    {
        $references = $this->scanner->find('![alt](./my%20pic.png)');

        self::assertSame('./my pic.png', $references[0]->path);
    }

    public function testReadsAUnicodePath(): void
    {
        $references = $this->scanner->find('![alt](./café.png)');

        self::assertSame('./café.png', $references[0]->path);
    }

    public function testReadsAUnicodePathWithASpaceInsideAngleBrackets(): void
    {
        $references = $this->scanner->find("![alt](<./Capture d'écran.png>)");

        self::assertSame("./Capture d'écran.png", $references[0]->path);
    }

    #[DataProvider('provideTitleForms')]
    public function testReadsEachTitleForm(string $title): void
    {
        $references = $this->scanner->find("![alt](./photo.png {$title})");

        self::assertCount(1, $references);
        self::assertSame('./photo.png', $references[0]->path);
    }

    /** @return iterable<array{0: string}> */
    public static function provideTitleForms(): iterable
    {
        yield ['"a title"'];
        yield ["'a title'"];
        yield ['(a title)'];
    }

    public function testIgnoresAReferenceInsideAFencedCodeBlock(): void
    {
        $markdown = "Before\n```\n![alt](./photo.png)\n```\nAfter";

        self::assertSame([], $this->scanner->find($markdown));
    }

    public function testIgnoresAReferenceInsideATildeFencedCodeBlock(): void
    {
        $markdown = "Before\n~~~\n![alt](./photo.png)\n~~~\nAfter";

        self::assertSame([], $this->scanner->find($markdown));
    }

    public function testIgnoresAReferenceInsideAnIndentedCodeBlock(): void
    {
        $markdown = "Paragraph.\n\n    ![alt](./photo.png)\n\nAfter.";

        self::assertSame([], $this->scanner->find($markdown));
    }

    public function testIgnoresAReferenceInsideSingleBacktickInlineCode(): void
    {
        self::assertSame([], $this->scanner->find('Text `![alt](./photo.png)` more'));
    }

    public function testIgnoresAReferenceInsideDoubleBacktickInlineCode(): void
    {
        self::assertSame([], $this->scanner->find('Text ``![alt](./photo.png)`` more'));
    }

    public function testStillFindsAReferenceOutsideACodeBlock(): void
    {
        $markdown = "```\nignored\n```\n![alt](./photo.png)";

        $references = $this->scanner->find($markdown);

        self::assertCount(1, $references);
        self::assertSame('./photo.png', $references[0]->path);
    }

    public function testFindsAnImageInsideALinkAndTheLinkItself(): void
    {
        $markdown = '[![alt](img/a.png)](doc.md)';

        $references = $this->scanner->find($markdown);

        self::assertEquals(
            [
                new MarkdownReference(MarkdownReferenceType::Image, 'img/a.png', null, strpos($markdown, 'img/a.png'), \strlen('img/a.png')),
                new MarkdownReference(MarkdownReferenceType::Link, 'doc.md', null, strpos($markdown, 'doc.md'), \strlen('doc.md')),
            ],
            $references,
        );
    }

    public function testReadsBalancedBracketsInTheText(): void
    {
        $references = $this->scanner->find('![a [b] c](./photo.png)');

        self::assertCount(1, $references);
        self::assertSame('./photo.png', $references[0]->path);
    }

    /** A paragraph or item of a nested list, indented by 4 after a blank line, is not a code block. */
    #[DataProvider('provideIndentedListContent')]
    public function testFindsAnIndentedReferenceInsideAList(string $markdown): void
    {
        $references = $this->scanner->find($markdown);

        self::assertCount(1, $references);
        self::assertSame('img/n.png', $references[0]->path);
    }

    /** @return iterable<string, array{0: string}> */
    public static function provideIndentedListContent(): iterable
    {
        yield 'paragraph of a nested item' => ["- a\n  - b\n\n    ![x](img/n.png)\n"];
        yield 'item of a loose nested list' => ["- a\n\n  - b\n\n    - ![x](img/n.png)\n"];
        yield 'ordered list' => ["1. a\n   1. b\n\n      ![x](img/n.png)\n"];
    }

    public function testIgnoresAnIndentedCodeBlockAfterAList(): void
    {
        $markdown = "- a\n\nParagraph.\n\n    ![alt](./photo.png)\n";

        self::assertSame([], $this->scanner->find($markdown));
    }
}
