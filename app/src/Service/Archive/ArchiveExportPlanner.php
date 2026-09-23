<?php

declare(strict_types=1);

namespace App\Service\Archive;

use App\Builder\ExportPlanBuilder;
use App\Dto\Archive\ExportPlan;
use App\Dto\Archive\ExportQueueItem;
use App\Dto\MarkdownReference;
use App\Enum\Archive\ArchiveExportRefusalReason;
use App\Enum\Archive\ExportIssueReason;
use App\Enum\DocumentExtension;
use App\Enum\MarkdownReferenceType;
use App\Exception\Archive\ArchiveExportRefusedException;
use App\Service\MarkdownDestinationWriter;
use App\Service\MarkdownReferenceRewriter;
use App\Service\MarkdownReferenceScanner;
use App\Service\Path\PathResolver;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Validator\Constraints\File as FileConstraint;

/**
 * Computes the archive plan for the export feature: which files go in,
 * where, and with which markdown rewritten so every reference still resolves
 * inside the archive. Writing the zip from this plan is a separate step (see
 * EDITOR_EXPORT.md, which this class follows point for point).
 *
 * Source is always the version saved on disk — read directly here, never the
 * editor's live content.
 */
final class ArchiveExportPlanner
{
    private const EXTERNAL_DIRS = ['ext_img', 'ext_md'];

    private const RESERVED_DIR_REASONS = [
        'ext_img' => ArchiveExportRefusalReason::ExtImgConflict,
        'ext_md' => ArchiveExportRefusalReason::ExtMdConflict,
    ];

    public function __construct(
        private readonly MarkdownReferenceScanner $referenceScanner,
        private readonly MarkdownDestinationWriter $destinationWriter,
        private readonly MarkdownReferenceRewriter $rewriter,
        private readonly PathResolver $pathResolver,
        private readonly Filesystem $filesystem,
        private readonly int $maxTotalFiles = 500,
        private readonly int $maxMarkdownHops = 3,
    ) {
    }

    public function plan(string $sourcePath, bool $includeExternalMarkdown): ExportPlan
    {
        $realSource = realpath($sourcePath);
        if ($realSource === false) {
            return new ExportPlan([], []);
        }

        if (is_dir($realSource)) {
            return $this->planDirectory($realSource, $includeExternalMarkdown);
        }

        return $this->planFile($realSource, $includeExternalMarkdown);
    }

    private function planDirectory(string $root, bool $includeExternalMarkdown): ExportPlan
    {
        foreach (self::EXTERNAL_DIRS as $reservedName) {
            $path = $root . '/' . $reservedName;
            if ($this->filesystem->exists($path) && !is_dir($path)) {
                throw new ArchiveExportRefusedException(self::RESERVED_DIR_REASONS[$reservedName]);
            }
        }

        $state = new ExportPlanBuilder($root, $this->maxTotalFiles);

        foreach (self::EXTERNAL_DIRS as $reservedName) {
            $this->reserveExistingNames($state, $root . '/' . $reservedName, $reservedName);
        }

        $queue = [];

        foreach ($this->findMarkdownFiles($root) as $relativePath) {
            $realPath = $root . '/' . $relativePath;
            $state->register($realPath, $relativePath);
            $queue[] = new ExportQueueItem($realPath, $relativePath, 0);
        }

        $this->processQueue($state, $queue, $includeExternalMarkdown);

        return $state->toPlan();
    }

    private function reserveExistingNames(ExportPlanBuilder $state, string $dir, string $archiveDir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $name) {
            if ('.' === $name || '..' === $name || is_dir($dir . '/' . $name)) {
                continue;
            }

            $state->reserve($archiveDir . '/' . $name);
        }
    }

    private function planFile(string $realPath, bool $includeExternalMarkdown): ExportPlan
    {
        if (!DocumentExtension::isDocument($realPath)) {
            throw new ArchiveExportRefusedException(ArchiveExportRefusalReason::UnsupportedFileType);
        }

        $state = new ExportPlanBuilder(null, $this->maxTotalFiles);
        $archivePath = basename($realPath);
        $state->register($realPath, $archivePath);

        $this->processQueue($state, [new ExportQueueItem($realPath, $archivePath, 0)], $includeExternalMarkdown);

        return $state->toPlan();
    }

    /** @return string[] paths relative to $root, '/'-separated */
    private function findMarkdownFiles(string $root): array
    {
        $finder = new Finder();
        $finder->in($root)->files()->ignoreDotFiles(false)->ignoreVCS(false);

        $paths = [];
        foreach ($finder as $fileInfo) {
            if (DocumentExtension::isDocument($fileInfo->getFilename())) {
                $paths[] = $fileInfo->getRelativePathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /** @param ExportQueueItem[] $queue */
    private function processQueue(ExportPlanBuilder $state, array $queue, bool $includeExternalMarkdown): void
    {
        while ($queue !== []) {
            $item = array_shift($queue);

            if (!$this->isAnalyzable($item->realPath)) {
                continue;
            }

            $raw = file_get_contents($item->realPath);
            if ($raw === false) {
                continue;
            }

            $edits = [];

            foreach ($this->referenceScanner->find($raw) as $reference) {
                $newDestination = $this->resolveReference($state, $item, $reference, $includeExternalMarkdown, $queue);
                if ($newDestination !== null) {
                    $edits[] = [$reference->destinationOffset, $reference->destinationLength, $newDestination];
                }
            }

            $state->setContent($item->archivePath, $this->rewriter->apply($raw, $edits));
        }
    }

    /**
     * @param ExportQueueItem[] &$queue
     *
     * @return string|null the new destination text to substitute at
     *                      $reference->destinationOffset, or null to leave
     *                      it untouched
     */
    private function resolveReference(
        ExportPlanBuilder $state,
        ExportQueueItem $item,
        MarkdownReference $reference,
        bool $includeExternalMarkdown,
        array &$queue,
    ): ?string {
        $isImage = MarkdownReferenceType::Image === $reference->type;

        // No extensions/mimeTypes here: the scanner already only produces a
        // reference for a recognised extension, and a genuine .md file's
        // guessed mime type ("text/plain") doesn't match File's implicit
        // extension-derived mime allowlist ("text/markdown", …) — this
        // constraint exists only to confirm the resolved path is a real,
        // readable file.
        $targetReal = $this->pathResolver->resolve($reference->path, $item->realPath, new FileConstraint());
        $originalTarget = $reference->path . ($reference->fragment ?? '');

        if (null === $targetReal) {
            $state->addIssue($item->realPath, $originalTarget, ExportIssueReason::NotFound);

            return null;
        }

        $isMarkdownLink = !$isImage && DocumentExtension::isDocument($targetReal);

        $archivePath = $state->archivePathFor($targetReal);

        if (null === $archivePath) {
            $isInternal = $state->isInternal($targetReal);

            if (!$isInternal && $isMarkdownLink && !$includeExternalMarkdown) {
                return null;
            }

            $hop = $item->hop + 1;
            if (!$isInternal && $isMarkdownLink && $hop > $this->maxMarkdownHops) {
                $state->addIssue($item->realPath, $originalTarget, ExportIssueReason::LimitExceeded);

                return null;
            }

            if ($state->isFull()) {
                $state->addIssue($item->realPath, $originalTarget, ExportIssueReason::LimitExceeded);

                return null;
            }

            $archivePath = $isInternal
                ? $state->relativeToRoot($targetReal)
                : $state->uniqueExternalPath($isImage ? 'ext_img' : 'ext_md', basename($targetReal));

            $state->register($targetReal, $archivePath, external: !$isInternal);

            if ($isMarkdownLink) {
                $queue[] = new ExportQueueItem($targetReal, $archivePath, $isInternal ? 0 : $hop);
            }
        }

        $newRelativePath = $this->relativePath($item->archivePath, $archivePath);

        // ARC-11: a reference already written the canonical way (its only
        // allowed slack is a redundant leading './') is left untouched, form
        // and all — anything else, absolute or a roundabout relative path
        // alike, is rewritten to it.
        $canonicalReferencePath = str_starts_with($reference->path, './') ? substr($reference->path, 2) : $reference->path;
        if ($canonicalReferencePath === $newRelativePath) {
            return null;
        }

        return $this->destinationWriter->write($newRelativePath) . ($reference->fragment ?? '');
    }

    private function isAnalyzable(string $realPath): bool
    {
        return DocumentExtension::isDocument($realPath);
    }

    private function relativePath(string $fromArchivePath, string $toArchivePath): string
    {
        $fromDir = \dirname($fromArchivePath);
        $toDir = \dirname($toArchivePath);

        $fromParts = '.' === $fromDir ? [] : explode('/', $fromDir);
        $toParts = '.' === $toDir ? [] : explode('/', $toDir);

        $common = 0;
        while ($common < \count($fromParts) && $common < \count($toParts) && $fromParts[$common] === $toParts[$common]) {
            ++$common;
        }

        $segments = [
            ...array_fill(0, \count($fromParts) - $common, '..'),
            ...\array_slice($toParts, $common),
            basename($toArchivePath),
        ];

        return implode('/', $segments);
    }
}
