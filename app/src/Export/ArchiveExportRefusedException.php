<?php

declare(strict_types=1);

namespace App\Export;

/**
 * Thrown when a directory export finds `ext_img` or `ext_md` in the source
 * folder as a plain file instead of a directory — merging exported content
 * into it (see EDITOR_EXPORT.md, "Révision du 2026-09-17") is then
 * impossible, so the whole export is refused rather than working around it.
 */
final class ArchiveExportRefusedException extends \RuntimeException
{
    public function __construct(public readonly string $reservedName)
    {
        parent::__construct(\sprintf('"%s" exists in the source folder and is not a directory.', $reservedName));
    }
}
