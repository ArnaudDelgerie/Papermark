<?php

declare(strict_types=1);

namespace App\File;

use App\Exception\File\RenameTargetExistsException;
use App\Exception\File\WriteFailedException;

/**
 * Renames without ever replacing a target that exists (lot 02-chemins.md,
 * "Renommage atomique"). Under Linux, link() refuses a target that's
 * already there — that refusal is the atomicity — then the source goes. On
 * a filesystem without hard links, link() fails for another reason: the
 * pair below falls back to the check-then-rename behaviour the app had
 * before, window and all, rather than refusing the rename outright.
 */
final class NoReplaceRename
{
    /**
     * @throws RenameTargetExistsException
     * @throws WriteFailedException
     */
    public function rename(string $source, string $target): void
    {
        if (@link($source, $target)) {
            if (@unlink($source)) {
                return;
            }

            // Half-done is worse than not done: undo, the source stays the truth.
            @unlink($target);

            throw new WriteFailedException($source);
        }

        if ($this->targetTaken($target)) {
            throw new RenameTargetExistsException($target);
        }

        if (!@rename($source, $target)) {
            throw new WriteFailedException($source);
        }
    }

    /** A dangling link is taken too: file_exists() alone wouldn't see it. */
    private function targetTaken(string $target): bool
    {
        return file_exists($target) || is_link($target);
    }
}
