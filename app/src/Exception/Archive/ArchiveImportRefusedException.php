<?php

declare(strict_types=1);

namespace App\Exception\Archive;

use App\Enum\Archive\ArchiveImportRefusalReason;

/**
 * Thrown when any single entry of the archive makes the whole import unsafe
 * or too large (see EDITOR_IMPORT.md, "Garde-fous") — the archive is refused
 * in full, never partially extracted.
 */
final class ArchiveImportRefusedException extends \RuntimeException
{
    public function __construct(public readonly ArchiveImportRefusalReason $reason)
    {
        parent::__construct(\sprintf('Archive import refused: %s.', $reason->value));
    }
}
