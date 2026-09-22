<?php

declare(strict_types=1);

namespace App\Dto\Archive;

use App\Enum\Setting\EditorMode;

/**
 * What ArchiveImporter::import() produced: the folder it created, what to
 * open there (a single file, the folder itself, or nothing — see
 * EDITOR_IMPORT.md, "Après l'import"), and the archive entries it left out
 * because their extension wasn't recognised.
 */
final class ImportResult
{
    /** @param string[] $ignoredEntries */
    public function __construct(
        public readonly string $destination,
        public readonly ?EditorMode $openMode,
        public readonly ?string $openPath,
        public readonly array $ignoredEntries,
    ) {
    }
}
