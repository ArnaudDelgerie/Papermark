<?php

declare(strict_types=1);

namespace App\File;

/**
 * The byte-level shape a file on disk had — its UTF-8 BOM, its dominant
 * line endings — reapplied to new content before it replaces the file
 * (lot 03-enregistrement.md, FIL-11): the editor serializes LF and strips
 * the BOM, and a Windows file must stay a Windows file. Nothing travels
 * with the document and nothing is memorized: save() rereads the file to
 * verify its revision anyway, and that read is where the shape is detected.
 *
 * A new file has no shape to copy: LF, no BOM — the caller never calls
 * apply() without disk bytes.
 */
final class DiskFormat
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * Puts $content in the shape $diskBytes had. Content that already
     * carries a BOM or stray CRs is normalized first, so the result is
     * deterministic whatever the editor serialized.
     */
    public function apply(string $content, string $diskBytes): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, \strlen(self::BOM));
        }

        if ($this->hasBom($diskBytes)) {
            $content = self::BOM . $content;
        }
        if ($this->isCrLf($diskBytes)) {
            $content = str_replace("\n", "\r\n", $content);
        }

        return $content;
    }

    public function hasBom(string $bytes): bool
    {
        return str_starts_with($bytes, self::BOM);
    }

    /**
     * Whether \r\n is the dominant line ending: at least one, and more
     * than the lone \n ones.
     */
    private function isCrLf(string $bytes): bool
    {
        $crlf = substr_count($bytes, "\r\n");
        if ($crlf === 0) {
            return false;
        }

        $loneLf = substr_count($bytes, "\n") - $crlf;

        return $crlf > $loneLf;
    }
}
