<?php

declare(strict_types=1);

namespace App\File;

/**
 * Reads a markdown/text file's content, converted to /file/image service
 * URLs — the same conversion FileController::open() applies over HTTP,
 * reused here so the session-remembered file can be embedded directly in the
 * editor page's first render instead of a second round trip (see
 * EDITOR_FIX.md).
 */
final class MarkdownFileReader
{
    private const ALLOWED_EXTENSIONS = ['md', 'markdown', 'txt'];

    public function __construct(
        private readonly MarkdownImageUrls $markdownImageUrls,
    ) {
    }

    public function read(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if (!\in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            return null;
        }

        $content = file_get_contents($realPath);
        if ($content === false) {
            return null;
        }

        return $this->markdownImageUrls->toServiceUrls($content, $realPath);
    }
}
