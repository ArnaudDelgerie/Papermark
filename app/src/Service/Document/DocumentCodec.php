<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Service\MarkdownReferenceScanner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The transformations between a document as the editor holds it and as it
 * sits on disk (lot 03-services-document.md):
 *
 * - toDisk(): the `<br>`s the editor's Markdown output leaves behind are
 *   stripped, image URLs go back to their raw path, then the file's own
 *   byte-level shape (BOM, dominant line endings) is reapplied — skipped for
 *   a new file, which has no shape to copy (FIL-11): the editor serializes
 *   LF and strips the BOM, and a Windows file must stay a Windows file.
 * - toEditor(): image raw paths become /document/image service URLs (see
 *   EDITOR_IMAGES.md).
 * - revision(): the xxh128 of a document's raw bytes — it describes the
 *   file, not what the editor shows of it.
 *
 * Only local-looking image paths are touched: a scheme (http:, data:, …)
 * means an external image, which this app doesn't manage — left untouched.
 * The image pattern itself lives in MarkdownReferenceScanner, reused here
 * rather than duplicated.
 */
final class DocumentCodec
{
    private const BOM = "\xEF\xBB\xBF";

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * $diskBytes is the file's current bytes, or null for one that doesn't
     * exist yet — the caller passes what DocumentStore's revision check
     * already read.
     */
    public function toDisk(string $content, ?string $diskBytes): string
    {
        $content = $this->stripLineBreaks($content);
        $content = $this->toRawPaths($content);

        return $diskBytes === null ? $content : $this->applyDiskFormat($content, $diskBytes);
    }

    public function toEditor(string $raw): string
    {
        return $this->toServiceUrls($raw);
    }

    public function revision(string $bytes): string
    {
        return hash('xxh128', $bytes);
    }

    private function stripLineBreaks(string $content): string
    {
        return preg_replace('/<br\s*\/?>\n?/i', '', $content) ?? $content;
    }

    private function toServiceUrls(string $markdown): string
    {
        return preg_replace_callback(
            MarkdownReferenceScanner::IMAGE_PATTERN,
            function (array $match): string {
                [, $alt, $path, $title] = $match + [2 => '', 3 => ''];

                if (!$this->isLocalPath($path)) {
                    return $match[0];
                }

                // The URL carries the path alone: the anchor that resolves a
                // relative one lives in the session, not in the markdown
                // (lot 02-chemins.md, SEC-04).
                $url = $this->urlGenerator->generate('app_document_image', ['path' => $path]);

                return "![{$alt}]({$url}{$title})";
            },
            $markdown,
        ) ?? $markdown;
    }

    private function toRawPaths(string $markdown): string
    {
        return preg_replace_callback(
            MarkdownReferenceScanner::IMAGE_PATTERN,
            function (array $match): string {
                [, $alt, $url, $title] = $match + [2 => '', 3 => ''];

                $path = $this->extractPath($url);
                if ($path === null) {
                    return $match[0];
                }

                return "![{$alt}]({$path}{$title})";
            },
            $markdown,
        ) ?? $markdown;
    }

    private function isLocalPath(string $path): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $path) !== 1;
    }

    private function extractPath(string $url): ?string
    {
        $prefix = '/document/image?';
        if (!str_starts_with($url, $prefix)) {
            return null;
        }

        // The Crepe/remark markdown serializer backslash-escapes ASCII
        // punctuation it finds "unsafe" inside a link/image destination —
        // notably the '&' separating our query params — per CommonMark's
        // backslash-escape rule. Undo that before parse_str() splits on '&',
        // or the escaped '&' leaves a stray '\' stuck on the previous value.
        $query = preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', substr($url, \strlen($prefix))) ?? substr($url, \strlen($prefix));

        parse_str($query, $parsed);

        return \is_string($parsed['path'] ?? null) ? $parsed['path'] : null;
    }

    /**
     * Puts $content in the shape $diskBytes had. Content that already
     * carries a BOM or stray CRs is normalized first, so the result is
     * deterministic whatever the editor serialized.
     */
    private function applyDiskFormat(string $content, string $diskBytes): string
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

    private function hasBom(string $bytes): bool
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
