<?php

declare(strict_types=1);

namespace App\Service\Archive;

/**
 * Turns the archive's own file name into the subfolder it gets extracted
 * into, inside the chosen parent directory (see EDITOR_IMPORT.md,
 * "Destination"): the archive name without ".zip", suffixed " (1)", " (2)", …
 * on collision — same convention as ArchiveTargetResolver, but for a folder
 * name instead of a file name, and never overwriting or merging.
 */
final class ImportTargetResolver
{
    public function resolve(string $parentDir, string $archiveFileName): string
    {
        $base = preg_replace('/\.zip$/i', '', $archiveFileName) ?? $archiveFileName;

        $candidate = $parentDir . '/' . $base;
        if (!file_exists($candidate)) {
            return $candidate;
        }

        $suffix = 1;
        do {
            $candidate = $parentDir . '/' . "{$base} ({$suffix})";
            ++$suffix;
        } while (file_exists($candidate));

        return $candidate;
    }
}
