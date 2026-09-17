<?php

declare(strict_types=1);

namespace App\Export;

final class ExportPlan
{
    /**
     * @param ExportEntry[] $entries
     * @param ExportIssue[] $issues
     */
    public function __construct(
        public readonly array $entries,
        public readonly array $issues,
    ) {
    }
}
