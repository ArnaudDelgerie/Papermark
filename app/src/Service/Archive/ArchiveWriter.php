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
     * @throws WriteFailedException
     */
    public function write(ExportPlan $plan, string $path): void
    {
        $zip = new \ZipArchive();
        $result = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if (true !== $result) {
            throw new WriteFailedException($path);
        }

        foreach ($plan->entries as $entry) {
            if (null !== $entry->content) {
                $zip->addFromString($entry->archivePath, $entry->content);
            } else {
                $zip->addFile($entry->sourcePath, $entry->archivePath);
            }
        }

        $zip->close();
    }
}
