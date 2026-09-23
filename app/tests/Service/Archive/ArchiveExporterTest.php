<?php

declare(strict_types=1);

namespace App\Tests\Service\Archive;

use App\Exception\Path\PathNotFoundException;
use App\Exception\Path\PathNotWritableException;
use App\Service\Archive\ArchiveExporter;
use App\Service\Archive\ArchiveExportPlanner;
use App\Service\Archive\ArchiveTargetResolver;
use App\Service\Archive\ArchiveWriter;
use App\Service\MarkdownDestinationWriter;
use App\Service\MarkdownReferenceRewriter;
use App\Service\MarkdownReferenceScanner;
use App\Service\Path\PathPolicy;
use App\Service\Path\PathResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Validation;

final class ArchiveExporterTest extends TestCase
{
    private string $root;
    private ArchiveExporter $exporter;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/archive_exporter_test_' . uniqid();
        mkdir($this->root, 0o777, true);

        $filesystem = new Filesystem();
        $this->exporter = new ArchiveExporter(
            new PathPolicy(new PathResolver($filesystem, Validation::createValidator())),
            new ArchiveTargetResolver(),
            new ArchiveExportPlanner(new MarkdownReferenceScanner(), new MarkdownDestinationWriter(), new MarkdownReferenceRewriter(), new PathResolver($filesystem, Validation::createValidator()), $filesystem),
            new ArchiveWriter(),
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
