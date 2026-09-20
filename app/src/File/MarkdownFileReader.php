<?php

declare(strict_types=1);

namespace App\File;

/**
 * Reads a markdown/text file's content, converted to /file/image service
 * URLs — what the editor fetches through EditorController::getFile() (see
 * EDITOR_REACTIVITY.md).
 */
final class MarkdownFileReader
{
    private const ALLOWED_EXTENSIONS = ['md', 'markdown', 'txt'];

    public function __construct(
        private readonly MarkdownImageUrls $markdownImageUrls,
    ) {
    }

    public function supports(string $path): bool
    {
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return \in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    public function read(string $path): ?string
    {
        if (!$this->supports($path)) {
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

        return $this->markdownImageUrls->toServiceUrls($content);
    }
}
