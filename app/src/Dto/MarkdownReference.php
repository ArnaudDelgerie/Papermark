<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\MarkdownReferenceType;

/**
 * One image or document reference found in a markdown document by
 * MarkdownReferenceScanner, for the export archive feature (see
 * EDITOR_EXPORT.md).
 *
 * $path never includes the anchor fragment; $fragment holds it (with its
 * leading '#'), when present, so a rewriter can reattach it to a new path.
 */
final class MarkdownReference
{
    public function __construct(
        public readonly MarkdownReferenceType $type,
        public readonly string $raw,
        public readonly string $path,
        public readonly ?string $fragment,
    ) {
    }
}
