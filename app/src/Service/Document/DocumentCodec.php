<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Enum\MarkdownReferenceType;
use App\Service\MarkdownDestinationWriter;
use App\Service\MarkdownReferenceRewriter;
use App\Service\MarkdownReferenceScanner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The transformations between a document as the editor holds it and as it
 * sits on disk (lot 03-services-document.md, image handling revised by lot
 * 05-markdown.md):
 *
 * - toDisk(): image URLs go back to their raw path, then the file's own
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
 * Finding a reference's destination is MarkdownReferenceScanner's job, not
 * duplicated here; both directions rewrite at the destination's exact
 * position (MarkdownReferenceRewriter), never by searching the text.
 */
final class DocumentCodec
{
    private const BOM = "\xEF\xBB\xBF";

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MarkdownReferenceScanner $referenceScanner,
        private readonly MarkdownDestinationWriter $destinationWriter,
        private readonly MarkdownReferenceRewriter $rewriter,
    ) {
    }

    /**
     * $diskBytes is the file's current bytes, or null for one that doesn't
     * exist yet — the caller passes what DocumentStore's revision check
     * already read.
     */
    public function toDisk(string $content, ?string $diskBytes): string
    {
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

    private function toServiceUrls(string $markdown): string
    {
        $edits = [];

        foreach ($this->referenceScanner->find($markdown) as $reference) {
            if (MarkdownReferenceType::Image !== $reference->type) {
                continue;
            }

            // The URL carries the path alone: the anchor that resolves a
            // relative one lives in the session, not in the markdown
            // (lot 02-chemins.md, SEC-04).
            $url = $this->urlGenerator->generate('app_document_image', ['path' => $reference->path]);
            $edits[] = [$reference->destinationOffset, $reference->destinationLength, $url . ($reference->fragment ?? '')];
        }

        return $this->rewriter->apply($markdown, $edits);
    }

    private function toRawPaths(string $markdown): string
    {
        $edits = [];

        foreach ($this->referenceScanner->find($markdown) as $reference) {
            if (MarkdownReferenceType::Image !== $reference->type) {
                continue;
            }

            $written = substr($markdown, $reference->destinationOffset, $reference->destinationLength);
            $path = $this->extractPath($this->stripAngleBrackets($written));
            if (null === $path) {
                continue;
            }

            $edits[] = [
                $reference->destinationOffset,
                $reference->destinationLength,
                $this->destinationWriter->write($path) . ($reference->fragment ?? ''),
            ];
        }

        return $this->rewriter->apply($markdown, $edits);
    }

    private function stripAngleBrackets(string $destination): string
    {
        if (str_starts_with($destination, '<') && str_ends_with($destination, '>')) {
            return substr($destination, 1, -1);
        }

        return $destination;
    }

    private function extractPath(string $url): ?string
    {
        // The same route toServiceUrls() generated, so the prefix follows it
        // (base URL included) instead of being written here a second time.
        $prefix = $this->urlGenerator->generate('app_document_image').'?';
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
