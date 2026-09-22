<?php

declare(strict_types=1);

namespace App\Service\Archive;

use App\Dto\Archive\ExportPlan;
use App\Service\Path\PathPolicy;

/**
 * The export operation as a whole (see EDITOR_EXPORT.md): source and target
 * pass PathPolicy first, then ArchiveTargetResolver turns the target into
 * its final path, ArchiveExportPlanner computes what goes in the archive,
 * and ArchiveWriter writes it.
 */
final class ArchiveExporter
{
    public function __construct(
        private readonly PathPolicy $pathPolicy,
        private readonly ArchiveTargetResolver $targetResolver,
        private readonly ArchiveExportPlanner $planner,
        private readonly ArchiveWriter $writer,
    ) {
    }

    /**
     * @return array{path: string, plan: ExportPlan}
     */
    public function export(string $source, string $target, bool $includeExternalMarkdown): array
    {
        $realSource = $this->pathPolicy->exportSource($source);
        $canonicalTarget = $this->pathPolicy->exportTarget($target);
        $resolvedTarget = $this->targetResolver->resolve($canonicalTarget);

        $plan = $this->planner->plan($realSource, $includeExternalMarkdown);
        $this->writer->write($plan, $resolvedTarget);

        return ['path' => $resolvedTarget, 'plan' => $plan];
    }
}
