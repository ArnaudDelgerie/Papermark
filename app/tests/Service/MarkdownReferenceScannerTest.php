<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\MarkdownReferenceType;
use App\Dto\MarkdownReference;
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
        $references = $this->scanner->find('Text ![alt](./img/photo.png "A title") more');

        self::assertEquals(
            [new MarkdownReference(MarkdownReferenceType::Image, '![alt](./img/photo.png "A title")', './img/photo.png', null)],
            $references,
        );
    }

    public function testFindsLocalMarkdownLink(): void
    {
        $references = $this->scanner->find('See [notes](./notes.md) for details');

        self::assertEquals(
            [new MarkdownReference(MarkdownReferenceType::Link, '[notes](./notes.md)', './notes.md', null)],
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
}
