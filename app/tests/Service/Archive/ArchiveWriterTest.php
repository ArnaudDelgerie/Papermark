<?php

declare(strict_types=1);

namespace App\Tests\Service\Archive;

use App\Service\Archive\ArchiveWriter;
use App\Dto\Archive\ExportEntry;
use App\Dto\Archive\ExportPlan;
use App\Exception\Filesystem\WriteFailedException;
use PHPUnit\Framework\TestCase;

final class ArchiveWriterTest extends TestCase
{
    private string $workDir;
    private string $zipPath;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/archive_writer_test_' . uniqid();
        mkdir($this->workDir, 0o777, true);
        $this->zipPath = $this->workDir . '/out.zip';
    }

    protected function tearDown(): void
    {
        @unlink($this->zipPath);
        $this->removeDirectory($this->workDir);
    }

    public function testWritesFileModeStructure(): void
    {
        $imagePath = $this->workDir . '/photo.png';
        file_put_contents($imagePath, 'PNG-BYTES');

        $plan = new ExportPlan(
            [
                new ExportEntry('doc.md', $this->workDir . '/doc.md', '![alt](ext_img/photo.png)'),
                new ExportEntry('ext_img/photo.png', $imagePath, null),
            ],
            [],
        );

        (new ArchiveWriter())->write($plan, $this->zipPath);

        $zip = new \ZipArchive();
        $zip->open($this->zipPath);

        self::assertSame(2, $zip->numFiles);
        self::assertSame('![alt](ext_img/photo.png)', $zip->getFromName('doc.md'));
        self::assertSame('PNG-BYTES', $zip->getFromName('ext_img/photo.png'));

        $zip->close();
    }

    public function testWritesDirectoryModeStructure(): void
    {
        $imagePath = $this->workDir . '/photo.png';
        file_put_contents($imagePath, 'PNG-BYTES');

        $plan = new ExportPlan(
            [
                new ExportEntry('markdown/sub/doc.md', $this->workDir . '/doc.md', 'content'),
                new ExportEntry('markdown/sub/img/photo.png', $imagePath, null),
            ],
            [],
        );

        (new ArchiveWriter())->write($plan, $this->zipPath);

        $zip = new \ZipArchive();
        $zip->open($this->zipPath);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = $zip->getNameIndex($i);
        }

        sort($names);
        self::assertSame(['markdown/sub/doc.md', 'markdown/sub/img/photo.png'], $names);

        $zip->close();
    }

    /** ARC-02: an addFile() source gone missing by write time fails the whole write, announcing nothing. */
    public function testUnreadableSourceFailsTheWriteAndAnnouncesNoFile(): void
    {
        $imagePath = $this->workDir . '/photo.png';
        file_put_contents($imagePath, 'PNG-BYTES');
        unlink($imagePath);

        $plan = new ExportPlan(
            [new ExportEntry('ext_img/photo.png', $imagePath, null)],
            [],
        );

        $this->expectException(WriteFailedException::class);

        try {
            (new ArchiveWriter())->write($plan, $this->zipPath);
        } finally {
            self::assertFileDoesNotExist($this->zipPath);
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
