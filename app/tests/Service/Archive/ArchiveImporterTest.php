<?php

declare(strict_types=1);

namespace App\Tests\Service\Archive;

use App\Enum\Archive\ArchiveImportRefusalReason;
use App\Enum\Setting\EditorMode;
use App\Event\ArchiveImported;
use App\Service\Archive\ArchiveImporter;
use App\Exception\Archive\ArchiveImportRefusedException;
use App\Service\Archive\ImportTargetResolver;
use App\Service\CloseGuard\BackendCloseGuardRunner;
use App\Service\Path\PathPolicy;
use App\Service\Path\PathResolver;
use App\Tests\Double\RecordingCloseGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Validation;

final class ArchiveImporterTest extends TestCase
{
    private string $workDir;
    private string $parentDir;
    private ArchiveImporter $importer;
    private EventDispatcher $eventDispatcher;
    private RecordingCloseGuard $guards;

    /** @var object[] */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/archive_importer_test_' . uniqid();
        $this->parentDir = $this->workDir . '/parent';
        mkdir($this->parentDir, 0o777, true);

        $this->eventDispatcher = new EventDispatcher();
        $this->dispatchedEvents = [];
        $this->eventDispatcher->addListener(ArchiveImported::class, function (object $event): void {
            $this->dispatchedEvents[] = $event;
        });

        $this->guards = new RecordingCloseGuard();
        $this->importer = $this->makeImporter();
    }

    private function makeImporter(int $maxTotalEntries = 200_000, int $maxTotalUncompressedBytes = 200 * 1024 * 1024): ArchiveImporter
    {
        return new ArchiveImporter(
            new PathPolicy(new PathResolver(new Filesystem(), Validation::createValidator())),
            new ImportTargetResolver(),
            $this->eventDispatcher,
            new BackendCloseGuardRunner($this->guards, new BufferingLogger()),
            $maxTotalEntries,
            $maxTotalUncompressedBytes,
        );
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

        self::assertCount(1, $this->dispatchedEvents);
        $event = $this->dispatchedEvents[0];
        self::assertInstanceOf(ArchiveImported::class, $event);
        self::assertSame($result->destination, $event->destination);
        self::assertSame($result->openMode, $event->openMode);
        self::assertSame($result->openPath, $event->openPath);
    }

    public function testArchiveImportedIsNotDispatchedWhenTheImportIsRefused(): void
    {
        $zipPath = $this->makeZip(['../escape.md' => '# Hello']);

        try {
            $this->importer->import($zipPath, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException) {
            // expected
        }

        self::assertSame([], $this->dispatchedEvents);
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

    public function testPreservesImagesAtTheirOriginalRelativePath(): void
    {
        $zipPath = $this->makeZip([
            'doc.md' => '# Hello ![alt](images/photo.png)',
            'images/photo.png' => 'PNG-BYTES',
        ]);

        $result = $this->importer->import($zipPath, $this->parentDir);

        self::assertFileExists($result->destination . '/images/photo.png');
        self::assertSame('PNG-BYTES', file_get_contents($result->destination . '/images/photo.png'));
        self::assertSame('# Hello ![alt](images/photo.png)', file_get_contents($result->destination . '/doc.md'));
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

    /** SVG is a document with scripts, not an image (lot 02, SEC-05). */
    public function testSvgEntriesAreIgnoredAndReported(): void
    {
        $zipPath = $this->makeZip([
            'doc.md' => '# Hello',
            'img/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'img/photo.png' => 'PNG-BYTES',
        ]);

        $result = $this->importer->import($zipPath, $this->parentDir);

        self::assertFileDoesNotExist($result->destination . '/img/logo.svg');
        self::assertFileExists($result->destination . '/img/photo.png');
        self::assertSame(['img/logo.svg'], $result->ignoredEntries);
    }

    /** ARC-07: nothing retained refuses the whole import instead of creating an empty folder. */
    public function testArchiveWithOnlyIgnoredEntriesIsRefused(): void
    {
        $zipPath = $this->makeZip(['notes.pdf' => 'not extracted']);

        try {
            $this->importer->import($zipPath, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException $e) {
            self::assertSame(ArchiveImportRefusalReason::Empty, $e->reason);
        }

        self::assertSame(['.', '..'], scandir($this->parentDir));
    }

    /** ARC-07: a 0-byte zip is accepted by ZipArchive::open() but has nothing to extract either. */
    public function testZeroByteZipIsRefused(): void
    {
        $zipPath = $this->workDir . '/zero.zip';
        file_put_contents($zipPath, '');

        try {
            $this->importer->import($zipPath, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException $e) {
            self::assertSame(ArchiveImportRefusalReason::Empty, $e->reason);
        }
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
        $importer = $this->makeImporter(maxTotalEntries: 1);
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
        $importer = $this->makeImporter(maxTotalUncompressedBytes: 5);
        $zipPath = $this->makeZip(['a.md' => 'more than five bytes']);

        try {
            $importer->import($zipPath, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException $e) {
            self::assertSame(ArchiveImportRefusalReason::TooLarge, $e->reason);
        }
    }

    /**
     * ARC-08: a large ignored entry never counts towards the size ceiling —
     * only the small, kept .md does.
     */
    public function testALargeIgnoredEntryDoesNotCountTowardsTheSizeLimit(): void
    {
        $importer = $this->makeImporter(maxTotalUncompressedBytes: 1024);
        $zipPath = $this->makeZip([
            'README.md' => 'four',
            'video.mp4' => str_repeat('V', 2048),
        ]);

        $result = $importer->import($zipPath, $this->parentDir);

        self::assertFileExists($result->destination . '/README.md');
        self::assertFileDoesNotExist($result->destination . '/video.mp4');
        self::assertSame(['video.mp4'], $result->ignoredEntries);
    }

    /**
     * SEC-03: the declared header size can't be trusted — the real ceiling
     * is enforced on the bytes actually streamed out during extraction, not
     * on ZipArchive::statIndex()'s reported (and falsifiable) size.
     */
    public function testRefusesArchiveOnceTheRealStreamedBytesExceedTheLimitEvenWithAFalsifiedDeclaredSize(): void
    {
        $importer = $this->makeImporter(maxTotalUncompressedBytes: 100);
        $content = str_repeat('AB', 5000); // 10,000 bytes, compresses to well under 100.
        $zipPath = $this->makeZipWithFalsifiedDeclaredSize('bomb.md', $content, 1);

        try {
            $importer->import($zipPath, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException $e) {
            self::assertSame(ArchiveImportRefusalReason::TooLarge, $e->reason);
        }

        self::assertSame(['.', '..'], scandir($this->parentDir));
    }

    /** ARC-06: an entry that can't be read through (here, encrypted without a password) refuses the whole import and leaves no residue. */
    public function testRefusesAnEncryptedEntryAndLeavesNoResidue(): void
    {
        $zipPath = $this->workDir . '/encrypted.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('doc.md', '# Hello');
        $zip->addFromString('secret.md', 'top secret');
        $zip->setEncryptionName('secret.md', \ZipArchive::EM_AES_256, 'hunter2');
        $zip->close();

        try {
            $this->importer->import($zipPath, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException $e) {
            self::assertSame(ArchiveImportRefusalReason::Unreadable, $e->reason);
        }

        self::assertSame(['.', '..'], scandir($this->parentDir));
    }

    public function testSuffixesDestinationWhenNameAlreadyTaken(): void
    {
        mkdir($this->parentDir . '/archive');
        $zipPath = $this->makeZip(['doc.md' => '# Hello']);

        $result = $this->importer->import($zipPath, $this->parentDir);

        self::assertSame($this->parentDir . '/archive (1)', $result->destination);
    }

    /** A running import guards the hub's last window for the whole operation, dispatch of ArchiveImported included (lot 03). */
    public function testASuccessfulImportRegistersThenRemovesAGuardWithAnImportId(): void
    {
        $zipPath = $this->makeZip(['doc.md' => '# Hello']);

        $this->importer->import($zipPath, $this->parentDir);

        self::assertCount(2, $this->guards->calls);
        self::assertSame('register', $this->guards->calls[0][0]);
        self::assertStringStartsWith('import:', $this->guards->calls[0][1]);
        self::assertSame($this->guards->calls[0][1], $this->guards->calls[1][1]);
        self::assertSame('remove', $this->guards->calls[1][0]);
    }

    /** A refused import is not work in progress anymore: its guard goes away with the refusal. */
    public function testARefusedImportRemovesItsGuardToo(): void
    {
        $zipPath = $this->makeZip(['../escape.md' => '# Hello']);

        try {
            $this->importer->import($zipPath, $this->parentDir);
            self::fail('Expected ArchiveImportRefusedException.');
        } catch (ArchiveImportRefusedException) {
            // expected
        }

        self::assertCount(2, $this->guards->calls);
        self::assertSame('register', $this->guards->calls[0][0]);
        self::assertStringStartsWith('import:', $this->guards->calls[0][1]);
        self::assertSame('remove', $this->guards->calls[1][0]);
    }

    public function testTwoImportsUseTwoDistinctGuardIds(): void
    {
        $firstZip = $this->makeZip(['doc.md' => '# Hello']);
        $secondZip = $this->makeZip(['notes.md' => '# Notes'], 'other.zip');

        $this->importer->import($firstZip, $this->parentDir);
        $this->importer->import($secondZip, $this->parentDir);

        $ids = array_unique(array_column($this->guards->calls, 1));
        self::assertCount(2, $ids);
        foreach ($ids as $id) {
            self::assertStringStartsWith('import:', $id);
        }
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

    /**
     * A single-entry zip whose local and central "uncompressed size" fields
     * are overwritten with $falsifiedSize, well below $content's real
     * length — what SEC-03's streamed byte count, not
     * ZipArchive::statIndex(), is meant to catch.
     */
    private function makeZipWithFalsifiedDeclaredSize(string $entryName, string $content, int $falsifiedSize): string
    {
        $zipPath = $this->workDir . '/falsified.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString($entryName, $content);
        $zip->setCompressionName($entryName, \ZipArchive::CM_DEFLATE);
        $zip->close();

        $bytes = file_get_contents($zipPath);
        self::assertNotFalse($bytes);
        self::assertSame("PK\x03\x04", substr($bytes, 0, 4), 'local file header must open the archive');

        $packedSize = pack('V', $falsifiedSize);
        $bytes = substr_replace($bytes, $packedSize, 22, 4);

        $centralOffset = strpos($bytes, "PK\x01\x02");
        self::assertNotFalse($centralOffset, 'central directory record not found');
        $bytes = substr_replace($bytes, $packedSize, $centralOffset + 24, 4);

        file_put_contents($zipPath, $bytes);

        return $zipPath;
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
