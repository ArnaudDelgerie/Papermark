<?php

declare(strict_types=1);

namespace App\File;

/**
 * Reads a markdown/text file's raw bytes — what a revision is hashed from
 * and what UTF-8 is checked on, since they describe the file and not what
 * the editor shows of it (lot 03-enregistrement.md, FIL-09). The /file/image
 * service-URL conversion that follows is MarkdownImageUrls', applied by the
 * controller once the bytes are known good (see EDITOR_IMAGES.md).
 */
final class MarkdownFileReader
{
    private const ALLOWED_EXTENSIONS = ['md', 'markdown', 'txt'];

    public function supports(string $path): bool
    {
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return \in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * The bytes as they are on disk, BOM and line endings included: an
     * empty file is a legitimate "" and only a failed read is null.
     */
    public function readRaw(string $path): ?string
    {
        if (!$this->supports($path)) {
            return null;
        }

        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            return null;
        }

        $content = file_get_contents($realPath);

        return $content === false ? null : $content;
    }
}
