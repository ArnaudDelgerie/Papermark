<?php

declare(strict_types=1);

namespace App\Service\Archive;

use App\Dto\Archive\ImportResult;
use App\Enum\Archive\ArchiveImportRefusalReason;
use App\Enum\DocumentExtension;
use App\Enum\Setting\EditorMode;
use App\Event\ArchiveImported;
use App\Exception\Archive\ArchiveImportRefusedException;
use App\Exception\Filesystem\WriteFailedException;
use App\Service\MarkdownReferenceScanner;
use App\Service\Path\PathPolicy;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Extracts a zip archive into a fresh subfolder of a chosen parent directory
 * (see EDITOR_IMPORT.md). Works on any zip, not just ones produced by
 * App\Service\Archive: only entries with a recognised markdown/image extension are
 * extracted, everything else is reported as ignored. The archive is
 * validated entry by entry before anything is written to disk — a single
 * unsafe entry, or one that pushes the real (not declared, see SEC-03)
 * uncompressed size beyond the ceiling, refuses the whole import (see
 * ArchiveImportRefusedException) — and extraction lands in a sibling
 * temporary folder, renamed into place only once complete, so a failure
 * never leaves a half-filled destination behind: any error past that point
 * removes the temporary folder before propagating (ARC-06). An archive with
 * nothing to keep is refused outright, before any folder is created
 * (ARC-07). Dispatches ArchiveImported once extraction succeeds; the cached
 * tree and the editor's own state react to it, not to this class.
 */
final class ArchiveImporter
{
    private const EXTERNAL_DIRS = ['ext_img', 'ext_md'];

    public function __construct(
        private readonly PathPolicy $pathPolicy,
        private readonly ImportTargetResolver $targetResolver,
        private readonly EventDispatcherInterface $eventDispatcher,
        // Same ceiling as DirectoryTree::DEFAULT_MAX_ITEMS: the editor's own
        // folder tree doesn't exclude node_modules/vendor either, so a
        // directory export of a real project routinely produces archives
        // with far more than a few hundred entries (see EDITOR_IMPORT.md).
        private readonly int $maxTotalEntries = 200_000,
        private readonly int $maxTotalUncompressedBytes = 200 * 1024 * 1024,
    ) {
    }

    public function import(string $archivePath, string $parentDir): ImportResult
    {
        $realArchivePath = $this->pathPolicy->importArchive($archivePath);
        $realParentDir = $this->pathPolicy->importInto($parentDir);

        if ('zip' !== strtolower(pathinfo($realArchivePath, \PATHINFO_EXTENSION))) {
            throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::NotAZip);
        }

        // A 0-byte file open()s as a fresh, empty archive (deprecated since
        // PHP 8.1) instead of failing: caught here, before that call, so it
        // takes the same "nothing to extract" refusal as ARC-07 rather than
        // a deprecation warning.
        if (0 === filesize($realArchivePath)) {
            throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::Empty);
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($realArchivePath)) {
            throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::NotAZip);
        }

        try {
            $result = $this->importFromZip($zip, $realArchivePath, $realParentDir);
        } finally {
            $zip->close();
        }

        $this->eventDispatcher->dispatch(new ArchiveImported($result->destination, $result->openMode, $result->openPath));

        return $result;
    }

    private function importFromZip(\ZipArchive $zip, string $archivePath, string $parentDir): ImportResult
    {
        if ($zip->numFiles > $this->maxTotalEntries) {
            throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::TooManyEntries);
        }

        $toExtract = [];
        $ignored = [];
        $declaredSize = 0;

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (false === $name) {
                continue;
            }

            $this->assertSafeName($name);
            $this->assertNotSymlink($zip, $i);

            if (str_ends_with($name, '/')) {
                continue;
            }

            if (!$this->isAccepted($name)) {
                $ignored[] = $name;

                continue;
            }

            // A first, cheap refusal on the size the zip declares — ARC-08:
            // only entries actually kept for extraction count. It can't be
            // trusted (SEC-03): the real ceiling is enforced below, on bytes
            // actually streamed out.
            $stat = $zip->statIndex($i);
            $declaredSize += false !== $stat ? $stat['size'] : 0;
            if ($declaredSize > $this->maxTotalUncompressedBytes) {
                throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::TooLarge);
            }

            $toExtract[] = $name;
        }

        if ([] === $toExtract) {
            throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::Empty);
        }

        $destination = $this->targetResolver->resolve($parentDir, basename($archivePath));
        $tmpDir = \dirname($destination) . '/.import_' . uniqid();

        if (!@mkdir($tmpDir, 0o777, true)) {
            throw new WriteFailedException($archivePath);
        }

        try {
            $this->extractStreamed($zip, $toExtract, $tmpDir);
        } catch (\Throwable $e) {
            $this->removeDirectory($tmpDir);

            throw $e;
        }

        if (!@rename($tmpDir, $destination)) {
            $this->removeDirectory($tmpDir);

            throw new WriteFailedException($archivePath);
        }

        [$openMode, $openPath] = $this->resolveOpenTarget($destination, $toExtract);

        return new ImportResult($destination, $openMode, $openPath, $ignored);
    }

    /**
     * Reads each kept entry through its own stream instead of
     * ZipArchive::extractTo(), counting the bytes it actually produces —
     * extractTo() trusts the header size ZipArchive::statIndex() reports,
     * which an archive can misstate (SEC-03). An entry that can't be opened
     * or read through (encrypted, corrupted CRC) is ARC-06's new refusal
     * reason, not a generic write failure.
     *
     * @param string[] $names
     */
    private function extractStreamed(\ZipArchive $zip, array $names, string $tmpDir): void
    {
        $totalBytes = 0;

        foreach ($names as $name) {
            $source = @$zip->getStream($name);
            if (false === $source) {
                throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::Unreadable);
            }

            try {
                $targetPath = $tmpDir . '/' . $name;
                $targetDir = \dirname($targetPath);
                if (!is_dir($targetDir) && !@mkdir($targetDir, 0o777, true) && !is_dir($targetDir)) {
                    throw new WriteFailedException($targetPath);
                }

                $target = @fopen($targetPath, 'wb');
                if (false === $target) {
                    throw new WriteFailedException($targetPath);
                }

                try {
                    while (!feof($source)) {
                        $chunk = @fread($source, 1024 * 1024);
                        if (false === $chunk) {
                            throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::Unreadable);
                        }

                        $totalBytes += \strlen($chunk);
                        if ($totalBytes > $this->maxTotalUncompressedBytes) {
                            throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::TooLarge);
                        }

                        if (false === @fwrite($target, $chunk)) {
                            throw new WriteFailedException($targetPath);
                        }
                    }
                } finally {
                    fclose($target);
                }
            } finally {
                fclose($source);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
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

    private function assertSafeName(string $name): void
    {
        if (str_starts_with($name, '/') || 1 === preg_match('#^[A-Za-z]:#', $name)) {
            throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::ZipSlip);
        }

        foreach (explode('/', $name) as $segment) {
            if ('..' === $segment) {
                throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::ZipSlip);
            }
        }
    }

    private function assertNotSymlink(\ZipArchive $zip, int $index): void
    {
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return;
        }

        if (\ZipArchive::OPSYS_UNIX !== $opsys) {
            return;
        }

        $unixMode = ($attr >> 16) & 0xF000;
        if (0xA000 === $unixMode) {
            throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::Symlink);
        }
    }

    /**
     * @param string[] $extractedNames
     *
     * @return array{0: ?EditorMode, 1: ?string}
     */
    private function resolveOpenTarget(string $destination, array $extractedNames): array
    {
        $documentNames = array_values(array_filter(
            $extractedNames,
            fn (string $name): bool => DocumentExtension::isDocument($name) && !$this->isUnderExternalDir($name),
        ));

        if (1 === \count($documentNames)) {
            return [EditorMode::Single, $destination . '/' . $documentNames[0]];
        }

        if ([] === $documentNames) {
            return [null, null];
        }

        return [EditorMode::Dir, $destination];
    }

    private function isAccepted(string $name): bool
    {
        if (DocumentExtension::isDocument($name)) {
            return true;
        }

        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));

        return \in_array($extension, MarkdownReferenceScanner::IMAGE_EXTENSIONS, true);
    }

    private function isUnderExternalDir(string $name): bool
    {
        $top = explode('/', $name, 2)[0];

        return \in_array($top, self::EXTERNAL_DIRS, true);
    }
}
