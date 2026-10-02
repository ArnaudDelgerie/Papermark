<?php

declare(strict_types=1);

namespace App\Dto\Archive;

use App\Enum\Archive\ExportIssueReason;

/**
 * A reference left untouched at its original path because it couldn't be
 * embarked: unresolvable, or beyond the export's chaining/count limits (see
 * EDITOR_EXPORT.md). Surfaced to the user in the export report.
 */
final class ExportIssue
{
    public function __construct(
        public readonly string $referencingPath,
        public readonly string $originalTarget,
        public readonly ExportIssueReason $reason,
    ) {
    }
}
