<?php

declare(strict_types=1);

namespace App\File;

use Symfony\Component\Filesystem\Exception\IOException;

/**
 * Writes a file whole, or not at all: the content goes to a temporary file
 * created in the destination folder, is fully written and fsync'd, then
 * published by rename() — a rename within the same folder is atomic, so a
 * process killed at any point leaves either the old or the new file, never
 * a half-written one (lot 03-enregistrement.md, FIL-02 and HUB-04).
 *
 * Not Symfony's Filesystem::dumpFile(): it follows symlinks — undoing the
 * lot 02 refusal in silence —, misses the short write, and creates the
 * parent folder.
 *
 * Two consequences, assumed: publishing by rename makes the file ours (a
 * new inode), and breaks a hard link — the other name keeps the old
 * content. Writing in place preserved both; that is the price of atomicity.
 *
 * No lock, no backup copy, no retry, no journal: the rest would be
 * over-engineering for a single-user desktop app.
 */
final class AtomicFileWriter
{
    /**
     * @param int|null $expectedLength the byte count the write must reach —
     *                                 a testing seam that simulates a short
     *                                 write (disk full); null means the
     *                                 content's own length
     *
     * @throws IOException when anything fails — the target is then left
     *                     exactly as it was
     */
    public function write(string $path, string $content, ?int $expectedLength = null): void
    {
        $expectedLength ??= \strlen($content);

        $dir = \dirname($path);
        $temp = tempnam($dir, '.papermark-save-');
        if ($temp === false) {
            throw new IOException("Could not create a temporary file in {$dir}.", 0, null, $dir);
        }

        try {
            $stream = fopen($temp, 'wb');
            if ($stream === false) {
                throw new IOException("Could not open {$temp} for writing.", 0, null, $temp);
            }

            try {
                $written = fwrite($stream, $content);
                // The check that closes FIL-02: a short write (disk full)
                // is an error, never a success.
                if ($written === false || $written !== $expectedLength) {
                    throw new IOException("Could not write the whole content of {$path}.", 0, null, $path);
                }
                if (!fflush($stream) || !fsync($stream)) {
                    throw new IOException("Could not flush {$temp} to disk.", 0, null, $temp);
                }
            } finally {
                fclose($stream);
            }

            // rename() only needs write access to the folder: the check that
            // keeps a read-only file unreplaceable stays with the caller
            // (is_writable in save()). Here the permissions just carry over,
            // so a private file stays private and a new one gets the umask
            // default.
            $permissions = file_exists($path) ? fileperms($path) : (0666 & ~umask());
            if ($permissions === false || !chmod($temp, $permissions & 07777)) {
                throw new IOException("Could not set the permissions of {$temp}.", 0, null, $temp);
            }

            if (!rename($temp, $path)) {
                throw new IOException("Could not publish {$temp} as {$path}.", 0, null, $path);
            }
        } finally {
            // Whatever happened, the temporary file leaves no trace — after
            // a successful rename it is already gone.
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
