<?php

declare(strict_types=1);

namespace App\Service\Archive;

use App\Dto\Archive\ExportPlan;
use App\Exception\Filesystem\WriteFailedException;

/**
 * Writes an ExportPlan to an actual zip file at an already-resolved path
 * (see ArchiveTargetResolver for turning a user-picked path into that final
 * path). A null ExportEntry::$content means copy the source file's bytes
 * as-is; a non-null one is written as the entry's content directly — see
 * EDITOR_EXPORT.md.
 */
final class ArchiveWriter
{
    /**
     * Written to a temporary file beside $path, renamed onto it only once
     * the zip is complete (ARC-02): an existing archive at $path stays as it
     * was if anything fails — ZipArchive::OVERWRITE on $path itself would
     * replace it with a partial zip as soon as close() succeeds.
     *
     * @throws WriteFailedException
     */
    public function write(ExportPlan $plan, string $path): void
    {
        $temp = @tempnam(\dirname($path), '.papermark-export-');
        if (false === $temp) {
            throw new WriteFailedException($path);
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($temp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            @unlink($temp);

            throw new WriteFailedException($path);
        }

        $allAdded = true;
        foreach ($plan->entries as $entry) {
            $added = null !== $entry->content
                ? @$zip->addFromString($entry->archivePath, $entry->content)
                : @$zip->addFile($entry->sourcePath, $entry->archivePath);
            $allAdded = $allAdded && $added;
        }

        $closed = @$zip->close();

        if (!$allAdded || !$closed || !is_file($temp) || !@rename($temp, $path)) {
            @unlink($temp);

            throw new WriteFailedException($path);
        }
    }
}
