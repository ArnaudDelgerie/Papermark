<?php

declare(strict_types=1);

namespace App\Tests\Service\Archive;

use App\Enum\Archive\ArchiveExportRefusalReason;
use App\Exception\Archive\ArchiveExportRefusedException;
use App\Exception\Path\PathNotFoundException;
use App\Exception\Path\PathNotWritableException;
use App\Service\Archive\ArchiveExporter;
use App\Service\Archive\ArchiveExportPlanner;
use App\Service\Archive\ArchiveTargetResolver;
use App\Service\Archive\ArchiveWriter;
use App\Service\CloseGuard\BackendCloseGuardRunner;
use App\Service\MarkdownDestinationWriter;
use App\Service\MarkdownReferenceRewriter;
use App\Service\MarkdownReferenceScanner;
use App\Service\Path\PathPolicy;
use App\Service\Path\PathResolver;
use App\Tests\Double\RecordingCloseGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Validation;

final class ArchiveExporterTest extends TestCase
{
    private string $root;
    private ArchiveExporter $exporter;
    private RecordingCloseGuard $guards;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/archive_exporter_test_' . uniqid();
        mkdir($this->root, 0o777, true);

        $this->guards = new RecordingCloseGuard();
        $filesystem = new Filesystem();
        $this->exporter = new ArchiveExporter(
            new PathPolicy(new PathResolver($filesystem, Validation::createValidator())),
            new ArchiveTargetResolver(),
            new ArchiveExportPlanner(new MarkdownReferenceScanner(), new MarkdownDestinationWriter(), new MarkdownReferenceRewriter(), new PathResolver($filesystem, Validation::createValidator()), $filesystem),
            new ArchiveWriter(),
            new BackendCloseGuardRunner($this->guards, new BufferingLogger()),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testWritesTheZipAndAppendsTheExtension(): void
    {
        $docPath = $this->root . '/doc.md';
        file_put_contents($docPath, '# Hello');

        $result = $this->exporter->export($docPath, $this->root . '/out', false);

        self::assertSame($this->root . '/out.zip', $result['path']);
        self::assertFileExists($result['path']);
        self::assertSame([], $result['plan']->issues);
    }

    public function testRefusesAMissingSource(): void
    {
        $this->expectException(PathNotFoundException::class);

        $this->exporter->export($this->root . '/missing.md', $this->root . '/out', false);
    }

    /** ARC-01: a source folder with nothing to embark is refused before the archive is opened, the existing target untouched. */
    public function testRefusesAnEmptyPlanAndLeavesAnExistingTargetUntouched(): void
    {
        $sourceDir = $this->root . '/notes';
        mkdir($sourceDir);
        file_put_contents($sourceDir . '/photo.png', 'PNG');

        $existingTarget = $this->root . '/out.zip';
        file_put_contents($existingTarget, 'not a real zip, but it must survive');

        try {
            $this->exporter->export($sourceDir, $this->root . '/out', false);
            self::fail('Expected ArchiveExportRefusedException.');
        } catch (ArchiveExportRefusedException $e) {
            self::assertSame(ArchiveExportRefusalReason::Empty, $e->reason);
        }

        self::assertSame('not a real zip, but it must survive', file_get_contents($existingTarget));
    }

    /** ARC-12: a file-mode source that isn't a document is refused outright. */
    public function testRefusesAnUnsupportedFileModeSource(): void
    {
        $photoPath = $this->root . '/photo.png';
        file_put_contents($photoPath, 'PNG-BYTES');

        try {
            $this->exporter->export($photoPath, $this->root . '/out', false);
            self::fail('Expected ArchiveExportRefusedException.');
        } catch (ArchiveExportRefusedException $e) {
            self::assertSame(ArchiveExportRefusalReason::UnsupportedFileType, $e->reason);
        }
    }

    public function testRefusesAnUnwritableTargetParent(): void
    {
        $docPath = $this->root . '/doc.md';
        file_put_contents($docPath, '# Hello');

        $readOnlyDir = $this->root . '/locked';
        mkdir($readOnlyDir);
        chmod($readOnlyDir, 0o555);

        try {
            $this->expectException(PathNotWritableException::class);

            $this->exporter->export($docPath, $readOnlyDir . '/out', false);
        } finally {
            chmod($readOnlyDir, 0o755);
        }
    }

    /** A running export guards the hub's last window for the whole operation, planning included (lot 03). */
    public function testASuccessfulExportRegistersThenRemovesAGuardWithAnExportId(): void
    {
        $docPath = $this->root . '/doc.md';
        file_put_contents($docPath, '# Hello');

        $this->exporter->export($docPath, $this->root . '/out', false);

        self::assertCount(2, $this->guards->calls);
        self::assertSame('register', $this->guards->calls[0][0]);
        self::assertStringStartsWith('export:', $this->guards->calls[0][1]);
        self::assertSame($this->guards->calls[0][1], $this->guards->calls[1][1]);
        self::assertSame('remove', $this->guards->calls[1][0]);
    }

    /** A refused export is not work in progress anymore: its guard goes away with the refusal. */
    public function testARefusedExportRemovesItsGuardToo(): void
    {
        $sourceDir = $this->root . '/notes';
        mkdir($sourceDir);
        file_put_contents($sourceDir . '/photo.png', 'PNG');

        try {
            $this->exporter->export($sourceDir, $this->root . '/out', false);
            self::fail('Expected ArchiveExportRefusedException.');
        } catch (ArchiveExportRefusedException) {
            // expected
        }

        self::assertCount(2, $this->guards->calls);
        self::assertSame('register', $this->guards->calls[0][0]);
        self::assertStringStartsWith('export:', $this->guards->calls[0][1]);
        self::assertSame('remove', $this->guards->calls[1][0]);
    }

    public function testTwoExportsUseTwoDistinctGuardIds(): void
    {
        $docPath = $this->root . '/doc.md';
        file_put_contents($docPath, '# Hello');

        $this->exporter->export($docPath, $this->root . '/out', false);
        $this->exporter->export($docPath, $this->root . '/out2', false);

        $ids = array_unique(array_column($this->guards->calls, 1));
        self::assertCount(2, $ids);
        foreach ($ids as $id) {
            self::assertStringStartsWith('export:', $id);
        }
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
