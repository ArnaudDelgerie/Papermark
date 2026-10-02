<?php

declare(strict_types=1);

namespace App\Dto\Editor;

use App\Enum\Setting\EditorMode;

/**
 * What EditorNavigator::openPath() opened: the real path, and the mode it
 * switched to (lot 04a).
 */
final readonly class OpenedPath
{
    public function __construct(
        public string $path,
        public EditorMode $mode,
    ) {
    }
}
