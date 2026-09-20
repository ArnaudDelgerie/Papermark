<?php

declare(strict_types=1);

namespace App\File;

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
    public function rename(string $source, string $target): NoReplaceRenameResult
    {
        if (@link($source, $target)) {
            if (@unlink($source)) {
                return NoReplaceRenameResult::Renamed;
            }

            // Half-done is worse than not done: undo, the source stays the truth.
            @unlink($target);

            return NoReplaceRenameResult::Failed;
        }

        if ($this->targetTaken($target)) {
            return NoReplaceRenameResult::TargetExists;
        }

        return @rename($source, $target) ? NoReplaceRenameResult::Renamed : NoReplaceRenameResult::Failed;
    }

    /** A dangling link is taken too: file_exists() alone wouldn't see it. */
    private function targetTaken(string $target): bool
    {
        return file_exists($target) || is_link($target);
    }
}
