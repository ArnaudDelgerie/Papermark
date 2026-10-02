<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\MarkdownReferenceType;

/**
 * One image or document reference found in a markdown document by
 * MarkdownReferenceScanner (lot 05-markdown.md).
 *
 * $path is decoded: backslash escapes undone, `<…>` unwrapped, `%XX`
 * percent-decoded (ARC-05) — the real filesystem path, never includes the
 * anchor fragment. $fragment holds the anchor (with its leading '#'), raw,
 * when present, so a rewriter can reattach it untouched.
 *
 * $destinationOffset/$destinationLength locate the destination exactly as
 * written in the source text — `<…>` wrapper included when present — so a
 * rewriter can replace it with substr_replace()-style precision instead of
 * searching for $raw, which breaks when the same reference appears twice.
 */
final class MarkdownReference
{
    public function __construct(
        public readonly MarkdownReferenceType $type,
        public readonly string $path,
        public readonly ?string $fragment,
        public readonly int $destinationOffset,
        public readonly int $destinationLength,
    ) {
    }
}
