<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Writes a path as a markdown destination (lot 05-markdown.md, FIL-08 /
 * ARC-04): the single function DocumentCodec and ArchiveExportPlanner both
 * call, so a path with a space or a parenthesis is written the same way
 * everywhere.
 *
 * A path with no space, no parenthesis and no `<`/`>` is written bare;
 * otherwise it's wrapped in `<…>`, with `\`, `<` and `>` inside it escaped so
 * MarkdownReferenceScanner reads the same path back.
 */
final class MarkdownDestinationWriter
{
    public function write(string $path): string
    {
        if (!$this->needsAngleBrackets($path)) {
            return $path;
        }

        return '<' . str_replace(['\\', '<', '>'], ['\\\\', '\\<', '\\>'], $path) . '>';
    }

    private function needsAngleBrackets(string $path): bool
    {
        return str_contains($path, ' ')
            || str_contains($path, '(')
            || str_contains($path, ')')
            || str_contains($path, '<')
            || str_contains($path, '>');
    }
}
