<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Editor\EditorMode;
use App\Import\ArchiveImportRefusalReason;
use App\Import\ArchiveImportRefusedException;
use App\Import\ArchiveImporter;
use App\Import\ImportTargetResolver;
use PHPUnit\Framework\TestCase;

final class ArchiveImporterTest extends TestCase
{
    private string $workDir;
    private string $parentDir;
    private ArchiveImporter $importer;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/archive_importer_test_' . uniqid();
        $this->parentDir = $this->workDir . '/parent';
        mkdir($this->parentDir, 0o777, true);
        $this->importer = new ArchiveImporter(new ImportTargetResolver());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
    }

    public function testImportsSingleFileArchive(): void
    {
        $zipPath = $this->makeZip(['doc.md' => '# Hello']);

        $result = $this->importer->import($zipPath, $this->parentDir);

        self::assertSame($this->parentDir . '/archive', $result->destination);
        self::assertFileExists($result->destination . '/doc.md');
        self::assertSame(EditorMode::Single, $result->openMode);
        self::assertSame($result->destination . '/doc.md', $result->openPath);
        self::assertSame([], $result->ignoredEntries);
    }

    public function testImportsDirectoryArchive(): void
    {
        $zipPath = $this->makeZip([
            'doc.md' => '# Hello',
            'notes.md' => '# Notes',
            'ext_img/photo.png' => 'PNG-BYTES',
        ]);

        $result = $this->importer->import($zipPath, $this->parentDir);

        self::assertFileExists($result->destination . '/doc.md');
        self::assertFileExists($result->destination . '/notes.md');
        self::assertFileExists($result->destination . '/ext_img/photo.png');
        self::assertSame(EditorMode::Dir, $result->openMode);
        self::assertSame($result->destination, $result->openPath);
    }

    public function testDirectoryArchiveWithSingleMarkdownOpensAsSingleFile(): void
    {
        $zipPath = $this->makeZip([
            'sub/doc.md' => '# Hello',
            'ext_img/photo.png' => 'PNG-BYTES',
        ]);

        $result = $this->importer->import($zipPath, $this->parentDir);

        self::assertSame(EditorMode::Single, $result->openMode);
        self::assertSame($result->destination . '/sub/doc.md', $result->openPath);
    }

    public function testArbitraryZipIsImported(): void
    {
        $zipPath = $this->makeZip(['readme.txt' => 'hello there']);

        $result = $this->importer->import($zipPath, $this->parentDir);

        self::assertFileExists($result->destination . '/readme.txt');
        self::assertSame(EditorMode::Single, $result->openMode);
    }

    public function testUnsupportedExtensionsAreIgnoredAndReported(): void
    {
        $zipPath = $this->makeZip([
            'doc.md' => '# Hello',
            'notes.pdf' => 'not extracted',
        ]);

        $result = $this->importer->import($zipPath, $this->parentDir);

        self::assertFileExists($result->destination . '/doc.md');
        self::assertFileDoesNotExist($result->destination . '/notes.pdf');
        self::assertSame(['notes.pdf'], $result->ignoredEntries);
    }

    public function testArchiveWithOnlyIgnoredEntriesReportsNoOpen(): void
    {
        $zipPath = $this->makeZip(['notes.pdf' => 'not extracted']);

        $result = $this->importer->import($zipPath, $this->parentDir);

        self::assertNull($result->openMode);
        self::assertNull($result->openPath);
        self::assertSame(['notes.pdf'], $result->ignoredEntries);
    }

    public function testRefusesParentTraversalEntry(): void
    {
        $zipPath = $this->makeZip(['../escape.md' => '# Hello']);

        try {
            $this->importer->import($zipPath, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException $e) {
            self::assertSame(ArchiveImportRefusalReason::ZipSlip, $e->reason);
        }
    }

    public function testRefusesAbsolutePathEntry(): void
    {
        $zipPath = $this->makeZip(['/etc/escape.md' => '# Hello']);

        try {
            $this->importer->import($zipPath, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException $e) {
            self::assertSame(ArchiveImportRefusalReason::ZipSlip, $e->reason);
        }
    }

    public function testRefusesSymlinkEntry(): void
    {
        $zipPath = $this->workDir . '/symlink.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('link.md', '/etc/passwd');
        $zip->setExternalAttributesName('link.md', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();

        try {
            $this->importer->import($zipPath, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException $e) {
            self::assertSame(ArchiveImportRefusalReason::Symlink, $e->reason);
        }
    }

    public function testRefusesArchiveBeyondEntryCountLimit(): void
    {
        $importer = new ArchiveImporter(new ImportTargetResolver(), maxTotalEntries: 1);
        $zipPath = $this->makeZip(['a.md' => 'a', 'b.md' => 'b']);

        try {
            $importer->import($zipPath, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException $e) {
            self::assertSame(ArchiveImportRefusalReason::TooManyEntries, $e->reason);
        }
    }

    public function testRefusesArchiveBeyondSizeLimit(): void
    {
        $importer = new ArchiveImporter(new ImportTargetResolver(), maxTotalUncompressedBytes: 5);
        $zipPath = $this->makeZip(['a.md' => 'more than five bytes']);

        try {
            $importer->import($zipPath, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException $e) {
            self::assertSame(ArchiveImportRefusalReason::TooLarge, $e->reason);
        }
    }

    public function testSuffixesDestinationWhenNameAlreadyTaken(): void
    {
        mkdir($this->parentDir . '/archive');
        $zipPath = $this->makeZip(['doc.md' => '# Hello']);

        $result = $this->importer->import($zipPath, $this->parentDir);

        self::assertSame($this->parentDir . '/archive (1)', $result->destination);
    }

    public function testRefusesNonZipFile(): void
    {
        $path = $this->workDir . '/not-a-zip.zip';
        file_put_contents($path, 'not actually a zip');

        try {
            $this->importer->import($path, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException $e) {
            self::assertSame(ArchiveImportRefusalReason::NotAZip, $e->reason);
        }
    }

    public function testRefusesFileWithoutZipExtension(): void
    {
        $path = $this->workDir . '/archive.txt';
        file_put_contents($path, 'whatever');

        try {
            $this->importer->import($path, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException $e) {
            self::assertSame(ArchiveImportRefusalReason::NotAZip, $e->reason);
        }
    }

    /** @param array<string, string> $entries */
    private function makeZip(array $entries, string $name = 'archive.zip'): string
    {
        $zipPath = $this->workDir . '/' . $name;

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }
        $zip->close();

        return $zipPath;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
