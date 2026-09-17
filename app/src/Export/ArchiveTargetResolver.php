<?php

declare(strict_types=1);

namespace App\Export;

/**
 * Turns the path save_path() returned into the actual path the archive gets
 * written to (see EDITOR_EXPORT.md). save_path() never writes and never
 * appends an extension itself:
 *
 * - no .zip extension: one is appended; if that name is already taken, a
 *   browser-download-style " (1)", " (2)", … suffix is inserted before it —
 *   the native picker never asked to overwrite this exact name, since it
 *   never saw the .zip extension.
 * - already ends in .zip: returned unchanged, even if it exists — the
 *   picker already confirmed the overwrite for that exact name.
 */
final class ArchiveTargetResolver
{
    public function resolve(string $requestedPath): string
    {
        if ('zip' === strtolower(pathinfo($requestedPath, \PATHINFO_EXTENSION))) {
            return $requestedPath;
        }

        $withExtension = $requestedPath . '.zip';
        if (!file_exists($withExtension)) {
            return $withExtension;
        }

        $dir = \dirname($requestedPath);
        $name = basename($requestedPath);

        $suffix = 1;
        do {
            $candidate = ('.' === $dir ? '' : $dir . '/') . "{$name} ({$suffix}).zip";
            ++$suffix;
        } while (file_exists($candidate));

        return $candidate;
    }
}
