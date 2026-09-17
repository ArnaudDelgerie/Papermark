<?php

declare(strict_types=1);

namespace App\Export;

/**
 * One markdown document still to be scanned for references while building
 * the export plan. $hop is the number of external-.md-to-external-.md links
 * crossed to reach it (0 for an internal document or an export root) — see
 * ArchiveExportPlanner and EDITOR_EXPORT.md's 3-hop limit.
 */
final class ExportQueueItem
{
    public function __construct(
        public readonly string $realPath,
        public readonly string $archivePath,
        public readonly int $hop,
    ) {
    }
}
