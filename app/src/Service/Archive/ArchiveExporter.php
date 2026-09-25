<?php

declare(strict_types=1);

namespace App\Service\Archive;

use App\Dto\Archive\ExportPlan;
use App\Enum\Archive\ArchiveExportRefusalReason;
use App\Exception\Archive\ArchiveExportRefusedException;
use App\Service\CloseGuard\BackendCloseGuardRunner;
use App\Service\Path\PathPolicy;

/**
 * The export operation as a whole (see EDITOR_EXPORT.md): source and target
 * pass PathPolicy first, then ArchiveTargetResolver turns the target into
 * its final path, ArchiveExportPlanner computes what goes in the archive,
 * and ArchiveWriter writes it. The whole export runs under a hub close
 * guard — the planning of a big folder is part of the work, not just the
 * writing — with a fresh random id per call, so two exports of the same
 * source stay two distinct works.
 */
final class ArchiveExporter
{
    public function __construct(
        private readonly PathPolicy $pathPolicy,
        private readonly ArchiveTargetResolver $targetResolver,
        private readonly ArchiveExportPlanner $planner,
        private readonly ArchiveWriter $writer,
        private readonly BackendCloseGuardRunner $closeGuards,
    ) {
    }

    /**
     * @return array{path: string, plan: ExportPlan}
     */
    public function export(string $source, string $target, bool $includeExternalMarkdown): array
    {
        return $this->closeGuards->run('export:'.bin2hex(random_bytes(8)), function () use ($source, $target, $includeExternalMarkdown): array {
            $realSource = $this->pathPolicy->exportSource($source);
            $canonicalTarget = $this->pathPolicy->exportTarget($target);
            $resolvedTarget = $this->targetResolver->resolve($canonicalTarget);

            $plan = $this->planner->plan($realSource, $includeExternalMarkdown);

            // Refused before the archive is even opened (ARC-01): an existing
            // target at $resolvedTarget is never touched.
            if ([] === $plan->entries) {
                throw new ArchiveExportRefusedException(ArchiveExportRefusalReason::Empty);
            }

            $this->writer->write($plan, $resolvedTarget);

            return ['path' => $resolvedTarget, 'plan' => $plan];
        });
    }
}
