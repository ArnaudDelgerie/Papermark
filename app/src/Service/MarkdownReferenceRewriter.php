<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Rebuilds a markdown document with a set of destinations replaced at their
 * exact position (lot 05-markdown.md) — used by DocumentCodec and
 * ArchiveExportPlanner instead of a text search, which breaks when the same
 * reference appears twice.
 */
final class MarkdownReferenceRewriter
{
    /** @param list<array{0: int, 1: int, 2: string}> $edits [offset, length, replacement], in ascending, non-overlapping offset order */
    public function apply(string $markdown, array $edits): string
    {
        $result = '';
        $cursor = 0;

        foreach ($edits as [$offset, $length, $replacement]) {
            $result .= substr($markdown, $cursor, $offset - $cursor);
            $result .= $replacement;
            $cursor = $offset + $length;
        }

        return $result . substr($markdown, $cursor);
    }
}
