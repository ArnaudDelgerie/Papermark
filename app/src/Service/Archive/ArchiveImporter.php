<?php

declare(strict_types=1);

namespace App\Service\Archive;

use App\Dto\Archive\ImportResult;
use App\Enum\Archive\ArchiveImportRefusalReason;
use App\Enum\Setting\EditorMode;
use App\Exception\Archive\ArchiveImportRefusedException;
use App\Exception\Filesystem\WriteFailedException;
use App\Service\MarkdownReferenceScanner;

/**
 * Extracts a zip archive into a fresh subfolder of a chosen parent directory
 * (see EDITOR_IMPORT.md). Works on any zip, not just ones produced by
 * App\Service\Archive: only entries with a recognised markdown/image extension are
 * extracted, everything else is reported as ignored. The archive is
 * validated entry by entry before anything is written to disk — a single
 * unsafe or oversized entry refuses the whole import (see
 * ArchiveImportRefusedException) — and extraction lands in a sibling
 * temporary folder, renamed into place only once complete, so a failure
 * never leaves a half-filled destination behind.
 */
final class ArchiveImporter
{
    private const EXTERNAL_DIRS = ['ext_img', 'ext_md'];

    /** @var string[] */
    private const ACCEPTED_EXTENSIONS = [
        ...MarkdownReferenceScanner::DOCUMENT_EXTENSIONS,
        ...MarkdownReferenceScanner::IMAGE_EXTENSIONS,
    ];

    public function __construct(
        private readonly ImportTargetResolver $targetResolver,
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
        if ('zip' !== strtolower(pathinfo($archivePath, \PATHINFO_EXTENSION))) {
            throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::NotAZip);
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($archivePath)) {
            throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::NotAZip);
        }

        try {
            return $this->importFromZip($zip, $archivePath, $parentDir);
        } finally {
            $zip->close();
        }
    }

    private function importFromZip(\ZipArchive $zip, string $archivePath, string $parentDir): ImportResult
    {
        if ($zip->numFiles > $this->maxTotalEntries) {
            throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::TooManyEntries);
        }

        $toExtract = [];
        $ignored = [];
        $totalSize = 0;

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (false === $name) {
                continue;
            }

            $this->assertSafeName($name);
            $this->assertNotSymlink($zip, $i);

            $stat = $zip->statIndex($i);
            $totalSize += false !== $stat ? $stat['size'] : 0;
            if ($totalSize > $this->maxTotalUncompressedBytes) {
                throw new ArchiveImportRefusedException(ArchiveImportRefusalReason::TooLarge);
            }

            if (str_ends_with($name, '/')) {
                continue;
            }

            $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
            if (!\in_array($extension, self::ACCEPTED_EXTENSIONS, true)) {
                $ignored[] = $name;

                continue;
            }

            $toExtract[] = $name;
        }

        $destination = $this->targetResolver->resolve($parentDir, basename($archivePath));
        $tmpDir = \dirname($destination) . '/.import_' . uniqid();
        mkdir($tmpDir, 0o777, true);

        if ([] !== $toExtract && !$zip->extractTo($tmpDir, $toExtract)) {
            throw new WriteFailedException($archivePath);
        }

        rename($tmpDir, $destination);

        [$openMode, $openPath] = $this->resolveOpenTarget($destination, $toExtract);

        return new ImportResult($destination, $openMode, $openPath, $ignored);
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
            fn (string $name): bool => $this->isDocument($name) && !$this->isUnderExternalDir($name),
        ));

        if (1 === \count($documentNames)) {
            return [EditorMode::Single, $destination . '/' . $documentNames[0]];
        }

        if ([] === $documentNames) {
            return [null, null];
        }

        return [EditorMode::Dir, $destination];
    }

    private function isDocument(string $name): bool
    {
        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));

        return \in_array($extension, MarkdownReferenceScanner::DOCUMENT_EXTENSIONS, true);
    }

    private function isUnderExternalDir(string $name): bool
    {
        $top = explode('/', $name, 2)[0];

        return \in_array($top, self::EXTERNAL_DIRS, true);
    }
}
