<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\Filesystem\DeleteFailedException;
use App\Exception\Filesystem\RenameTargetExistsException;
use App\Exception\Filesystem\WriteFailedException;

/**
 * The disk, tout ou rien (lot 03-services-document.md): write, rename and
 * delete each succeed completely or leave the target untouched, and never
 * leak a Symfony IOException — only this app's own exceptions.
 *
 * No lock, no backup copy, no retry, no journal: the rest would be
 * over-engineering for a single-user desktop app.
 */
final class SafeFilesystem
{
    /**
     * Writes $path whole, or not at all: the content goes to a temporary file
     * created in the destination folder, is fully written and fsync'd, then
     * published by rename() — a rename within the same folder is atomic, so a
     * process killed at any point leaves either the old or the new file,
     * never a half-written one (FIL-02, HUB-04).
     *
     * Not Symfony's Filesystem::dumpFile(): it follows symlinks — undoing the
     * lot 02 refusal in silence —, misses the short write, and creates the
     * parent folder.
     *
     * Two consequences, assumed: publishing by rename makes the file ours (a
     * new inode), and breaks a hard link — the other name keeps the old
     * content. Writing in place preserved both; that is the price of
     * atomicity.
     *
     * @param int|null $expectedLength the byte count the write must reach —
     *                                 a testing seam that simulates a short
     *                                 write (disk full); null means the
     *                                 content's own length
     *
     * @throws WriteFailedException when anything fails — the target is then
     *                              left exactly as it was
     */
    public function write(string $path, string $content, ?int $expectedLength = null): void
    {
        $expectedLength ??= \strlen($content);

        $dir = \dirname($path);
        $temp = tempnam($dir, '.papermark-save-');
        if ($temp === false) {
            throw new WriteFailedException($path);
        }

        try {
            $stream = fopen($temp, 'wb');
            if ($stream === false) {
                throw new WriteFailedException($path);
            }

            try {
                $written = fwrite($stream, $content);
                // The check that closes FIL-02: a short write (disk full)
                // is an error, never a success.
                if ($written === false || $written !== $expectedLength) {
                    throw new WriteFailedException($path);
                }
                if (!fflush($stream) || !fsync($stream)) {
                    throw new WriteFailedException($path);
                }
            } finally {
                fclose($stream);
            }

            // rename() only needs write access to the folder: the check that
            // keeps a read-only file unreplaceable is PathPolicy::save's.
            // Here the permissions just carry over, so a private file stays
            // private and a new one gets the umask default.
            $permissions = file_exists($path) ? fileperms($path) : (0666 & ~umask());
            if ($permissions === false || !chmod($temp, $permissions & 07777)) {
                throw new WriteFailedException($path);
            }

            if (!rename($temp, $path)) {
                throw new WriteFailedException($path);
            }
        } finally {
            // Whatever happened, the temporary file leaves no trace — after
            // a successful rename it is already gone.
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Renames without ever replacing a target that exists. Under Linux,
     * link() refuses a target that's already there — that refusal is the
     * atomicity — then the source goes. On a filesystem without hard links,
     * link() fails for another reason: the pair below falls back to the
     * check-then-rename behaviour the app had before, window and all, rather
     * than refusing the rename outright.
     *
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

    /** @throws DeleteFailedException */
    public function delete(string $path): void
    {
        if (!@unlink($path)) {
            throw new DeleteFailedException($path);
        }
    }

    /** A dangling link is taken too: file_exists() alone wouldn't see it. */
    private function targetTaken(string $target): bool
    {
        return file_exists($target) || is_link($target);
    }
}
