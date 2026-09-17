<?php

declare(strict_types=1);

namespace App\Export;

/**
 * Mutable working state for one ArchiveExportPlanner::plan() call: the
 * archive path assigned to each real path seen so far (for dedup and
 * cross-referencing), name-collision bookkeeping inside ext_img/ext_md, and
 * the growing entry/issue lists. A fresh instance per call, never a service.
 */
final class ExportPlanState
{
    /** @var array<string, string> real path => archive path */
    private array $archivePathsByRealPath = [];

    /** @var array<string, ExportEntry> archive path => entry */
    private array $entries = [];

    /** @var ExportIssue[] */
    private array $issues = [];

    /** @var array<string, true> archive path => true, to detect suffix collisions */
    private array $takenArchivePaths = [];

    /** @var array<string, int> "dir/original name" => next suffix to try */
    private array $nextSuffix = [];

    public function __construct(
        private readonly ?string $root,
        private readonly int $maxTotalFiles,
    ) {
    }

    public function isInternal(string $realPath): bool
    {
        return $this->root !== null
            && ($realPath === $this->root || str_starts_with($realPath, $this->root . '/'));
    }

    public function relativeToRoot(string $realPath): string
    {
        \assert($this->root !== null);

        return substr($realPath, \strlen($this->root) + 1);
    }

    public function has(string $realPath): bool
    {
        return isset($this->archivePathsByRealPath[$realPath]);
    }

    public function archivePathFor(string $realPath): ?string
    {
        return $this->archivePathsByRealPath[$realPath] ?? null;
    }

    public function isFull(): bool
    {
        return \count($this->entries) >= $this->maxTotalFiles;
    }

    /**
     * Marks an archive path as taken without registering an entry for it —
     * used to reserve the names already present in the source's ext_img/
     * ext_md before assigning external copies (see
     * ArchiveExportPlanner::planDirectory()).
     */
    public function reserve(string $archivePath): void
    {
        $this->takenArchivePaths[$archivePath] = true;
    }

    public function uniqueExternalPath(string $dir, string $name): string
    {
        $candidate = $dir . '/' . $name;
        if (!isset($this->takenArchivePaths[$candidate])) {
            $this->takenArchivePaths[$candidate] = true;

            return $candidate;
        }

        $extension = pathinfo($name, \PATHINFO_EXTENSION);
        $base = $extension === '' ? $name : substr($name, 0, -(\strlen($extension) + 1));
        $key = $dir . '/' . $name;

        do {
            $this->nextSuffix[$key] = ($this->nextSuffix[$key] ?? 0) + 1;
            $suffixed = $extension === ''
                ? "{$base} ({$this->nextSuffix[$key]})"
                : "{$base} ({$this->nextSuffix[$key]}).{$extension}";
            $candidate = $dir . '/' . $suffixed;
        } while (isset($this->takenArchivePaths[$candidate]));

        $this->takenArchivePaths[$candidate] = true;

        return $candidate;
    }

    public function register(string $realPath, string $archivePath): void
    {
        $this->archivePathsByRealPath[$realPath] = $archivePath;
        $this->takenArchivePaths[$archivePath] = true;
        $this->entries[$archivePath] = new ExportEntry($archivePath, $realPath, null);
    }

    public function setContent(string $archivePath, string $content): void
    {
        $entry = $this->entries[$archivePath];
        $this->entries[$archivePath] = new ExportEntry($entry->archivePath, $entry->sourcePath, $content);
    }

    public function addIssue(string $referencingPath, string $originalTarget, ExportIssueReason $reason): void
    {
        $this->issues[] = new ExportIssue($referencingPath, $originalTarget, $reason);
    }

    public function toPlan(): ExportPlan
    {
        return new ExportPlan(array_values($this->entries), $this->issues);
    }
}
