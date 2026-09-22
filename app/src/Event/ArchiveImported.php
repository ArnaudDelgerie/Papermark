<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\Setting\EditorMode;

/** Dispatched by ArchiveImporter::import once the extraction has succeeded. */
final class ArchiveImported
{
    public function __construct(
        public readonly string $destination,
        public readonly ?EditorMode $openMode,
        public readonly ?string $openPath,
    ) {
    }
}
