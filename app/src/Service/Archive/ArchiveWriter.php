<?php

declare(strict_types=1);

namespace App\Service\Archive;

use App\Dto\Archive\ExportPlan;

/**
 * Writes an ExportPlan to an actual zip file at an already-resolved path
 * (see ArchiveTargetResolver for turning a user-picked path into that final
 * path). A null ExportEntry::$content means copy the source file's bytes
 * as-is; a non-null one is written as the entry's content directly — see
 * EDITOR_EXPORT.md.
 */
final class ArchiveWriter
{
    public function write(ExportPlan $plan, string $path): void
    {
        $zip = new \ZipArchive();
        $result = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if (true !== $result) {
            throw new \RuntimeException(\sprintf('Could not create zip archive at "%s" (code %d).', $path, $result));
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
