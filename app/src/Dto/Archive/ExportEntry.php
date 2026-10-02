<?php

declare(strict_types=1);

namespace App\Dto\Archive;

/**
 * One file the export archive will contain (see EDITOR_EXPORT.md).
 *
 * $content is the rewritten markdown to write for a .md/.markdown source,
 * with every reference pointing at its new archive location; null means
 * "copy $sourcePath's bytes as-is" (images, .txt, anything not analysed).
 */
final class ExportEntry
{
    public function __construct(
        public readonly string $archivePath,
        public readonly string $sourcePath,
        public readonly ?string $content,
    ) {
    }
}
