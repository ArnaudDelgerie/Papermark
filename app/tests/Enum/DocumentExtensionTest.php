<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DocumentExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentExtensionTest extends TestCase
{
    #[DataProvider('provideDocumentPaths')]
    public function testRecognisesEachDocumentExtension(string $path): void
    {
        self::assertTrue(DocumentExtension::isDocument($path));
    }

    /** @return iterable<array{0: string}> */
    public static function provideDocumentPaths(): iterable
    {
        yield ['doc.md'];
        yield ['doc.markdown'];
        yield ['doc.txt'];
        yield ['doc.MD'];
        yield ['/a/b/doc.markdown'];
    }

    public function testRefusesAnUnrecognisedExtension(): void
    {
        self::assertFalse(DocumentExtension::isDocument('doc.exe'));
    }

    public function testRefusesAPathWithoutAnExtension(): void
    {
        self::assertFalse(DocumentExtension::isDocument('doc'));
    }
}
