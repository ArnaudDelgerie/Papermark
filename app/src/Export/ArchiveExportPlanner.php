<?php

declare(strict_types=1);

namespace App\Export;

use App\Enum\Archive\ExportIssueReason;
use App\Enum\File\MarkdownReferenceType;
use App\File\MarkdownReference;
use App\File\MarkdownReferenceScanner;
use App\File\PathResolver;
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
    private const DOCUMENT_LINK_EXTENSIONS = ['md', 'markdown'];
    private const EXTERNAL_DIRS = ['ext_img', 'ext_md'];

    public function __construct(
        private readonly MarkdownReferenceScanner $referenceScanner,
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
                throw new ArchiveExportRefusedException($reservedName);
            }
        }

        $state = new ExportPlanState($root, $this->maxTotalFiles);

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

    private function reserveExistingNames(ExportPlanState $state, string $dir, string $archiveDir): void
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
        $state = new ExportPlanState(null, $this->maxTotalFiles);
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
            if (strtolower($fileInfo->getExtension()) === 'md') {
                $paths[] = $fileInfo->getRelativePathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /** @param ExportQueueItem[] $queue */
    private function processQueue(ExportPlanState $state, array $queue, bool $includeExternalMarkdown): void
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

            $content = $raw;

            foreach ($this->referenceScanner->find($raw) as $reference) {
                $rewritten = $this->resolveReference($state, $item, $reference, $includeExternalMarkdown, $queue);
                if ($rewritten !== null) {
                    $content = str_replace($reference->raw, $rewritten, $content);
                }
            }

            $state->setContent($item->archivePath, $content);
        }
    }

    /**
     * @param ExportQueueItem[] &$queue
     *
     * @return string|null the raw markdown to substitute in place of
     *                      $reference->raw, or null to leave it untouched
     */
    private function resolveReference(
        ExportPlanState $state,
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

        $targetExtension = strtolower(pathinfo($targetReal, \PATHINFO_EXTENSION));
        $isMarkdownLink = !$isImage && \in_array($targetExtension, self::DOCUMENT_LINK_EXTENSIONS, true);

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

            $state->register($targetReal, $archivePath);

            if ($isMarkdownLink) {
                $queue[] = new ExportQueueItem($targetReal, $archivePath, $isInternal ? 0 : $hop);
            }
        }

        $bothInternal = $state->isInternal($item->realPath) && $state->isInternal($targetReal);
        if ($bothInternal && !$this->filesystem->isAbsolutePath($reference->path)) {
            return null;
        }

        $newTarget = $this->relativePath($item->archivePath, $archivePath) . ($reference->fragment ?? '');

        return $this->rewriteRaw($reference, $newTarget);
    }

    private function isAnalyzable(string $realPath): bool
    {
        $extension = strtolower(pathinfo($realPath, \PATHINFO_EXTENSION));

        return \in_array($extension, self::DOCUMENT_LINK_EXTENSIONS, true);
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

    private function rewriteRaw(MarkdownReference $reference, string $newTarget): string
    {
        $originalTarget = $reference->path . ($reference->fragment ?? '');
        $pos = strpos($reference->raw, '(' . $originalTarget);

        if (false === $pos) {
            return $reference->raw;
        }

        return substr_replace($reference->raw, $newTarget, $pos + 1, \strlen($originalTarget));
    }
}
