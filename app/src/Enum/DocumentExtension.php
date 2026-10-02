<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The extensions the editor treats as a document: what the tree lists, the
 * export walks, the importer extracts, and what save/rename/setFile accept.
 * Replaces every hand-written list of 'md', 'markdown', 'txt' (lot
 * 03-services-document.md).
 */
enum DocumentExtension: string
{
    case Markdown = 'md';
    case MarkdownLong = 'markdown';
    case Text = 'txt';

    public static function isDocument(string $path): bool
    {
        return self::tryFrom(strtolower(pathinfo($path, \PATHINFO_EXTENSION))) !== null;
    }
}
