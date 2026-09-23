<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\MarkdownReference;
use App\Enum\DocumentExtension;
use App\Enum\MarkdownReferenceType;

/**
 * Finds the image and local-document references inside a markdown document
 * (lot 05-markdown.md, replacing the two regexes of lot 03-services-document.md).
 * Used by the export archive feature and by DocumentCodec.
 *
 * Understands, and nothing more:
 *
 * - images `![…](…)` and links `[…](…)`, with an optional title in `"…"`,
 *   `'…'` or `(…)`;
 * - a destination written as `<…>`, or bare with balanced parentheses and
 *   `\`-escaped punctuation;
 * - fenced code blocks (``` or ~~~) and indented code blocks, and inline
 *   code spans (one or more backticks) — skipped whole, never scanned.
 *
 * A reference is only recognised when its decoded path has a known
 * extension: IMAGE_EXTENSIONS for an image, DocumentExtension for a link.
 * Same local-path rule throughout: a scheme (http:, data:, …) means an
 * external reference, left alone — as is an `<img>` tag, which this reader
 * never matches. An anchor (`#…`), if any, is split off into
 * MarkdownReference::$fragment.
 */
final class MarkdownReferenceScanner
{
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'];

    /** @return MarkdownReference[] in document order */
    public function find(string $markdown): array
    {
        $masked = $this->maskCodeRegions($markdown);
        $length = \strlen($markdown);
        $references = [];
        $pos = 0;

        while ($pos < $length) {
            $char = $masked[$pos];

            if ('!' !== $char && '[' !== $char) {
                ++$pos;
                continue;
            }

            $isImage = '!' === $char;
            $bracketPos = $pos;
            if ($isImage) {
                if ($pos + 1 >= $length || '[' !== $masked[$pos + 1]) {
                    ++$pos;
                    continue;
                }
                $bracketPos = $pos + 1;
            }

            $closeBracket = $this->findCloseBracket($masked, $bracketPos, $length);
            if (null === $closeBracket || $closeBracket + 1 >= $length || '(' !== $masked[$closeBracket + 1]) {
                $pos = $bracketPos + 1;
                continue;
            }

            $parsed = $this->parseReference($markdown, $masked, $closeBracket + 1, $length);
            if (null === $parsed) {
                $pos = $bracketPos + 1;
                continue;
            }

            $reference = $this->buildReference($isImage, $parsed);
            if (null !== $reference) {
                $references[] = $reference;
            }

            // A link's text may hold an image (`[![alt](a.png)](doc.md)`):
            // read on from inside it rather than jumping past the link.
            $pos = $isImage ? $parsed['end'] + 1 : $bracketPos + 1;
        }

        // An image inside a link is found after the link, but its
        // destination comes first: rewriters need them in text order.
        usort($references, static fn (MarkdownReference $a, MarkdownReference $b): int => $a->destinationOffset <=> $b->destinationOffset);

        return $references;
    }

    /** The `]` closing the `[` at $openPos, brackets nested inside counted, or null. */
    private function findCloseBracket(string $masked, int $openPos, int $length): ?int
    {
        $depth = 0;
        for ($i = $openPos + 1; $i < $length; ++$i) {
            $char = $masked[$i];
            if ('\\' === $char) {
                ++$i;
            } elseif ('[' === $char) {
                ++$depth;
            } elseif (']' === $char) {
                if (0 === $depth) {
                    return $i;
                }
                --$depth;
            }
        }

        return null;
    }

    /**
     * @return array{destStart: int, destEnd: int, raw: string, end: int}|null
     *               the destination's own [destStart, destEnd) in the source
     *               text (angle brackets included when present) and its
     *               inner text (brackets excluded); $end is the position of
     *               the closing ')' of the whole `(dest [title])` group, for
     *               the caller to resume scanning after it. Null if no valid
     *               `(dest [title])` starts here
     */
    private function parseReference(string $markdown, string $masked, int $openParenPos, int $length): ?array
    {
        $pos = $this->skipWhitespace($masked, $openParenPos + 1, $length);

        $destination = $this->parseDestination($markdown, $masked, $pos, $length);
        if (null === $destination) {
            return null;
        }

        $result = ['destStart' => $destination['start'], 'destEnd' => $destination['end'], 'raw' => $destination['raw']];

        $afterDestination = $destination['end'];
        $afterSpace = $this->skipWhitespace($masked, $afterDestination, $length);

        if ($afterSpace < $length && ')' === $masked[$afterSpace]) {
            return $result + ['end' => $afterSpace];
        }

        if ($afterSpace > $afterDestination) {
            $titleEnd = $this->parseTitle($masked, $afterSpace, $length);
            if (null !== $titleEnd) {
                $afterTitle = $this->skipWhitespace($masked, $titleEnd, $length);
                if ($afterTitle < $length && ')' === $masked[$afterTitle]) {
                    return $result + ['end' => $afterTitle];
                }
            }
        }

        return null;
    }

    /** @return array{start: int, end: int, raw: string}|null */
    private function parseDestination(string $markdown, string $masked, int $pos, int $length): ?array
    {
        if ($pos < $length && '<' === $masked[$pos]) {
            return $this->parseAngleDestination($markdown, $masked, $pos, $length);
        }

        return $this->parseBareDestination($markdown, $masked, $pos, $length);
    }

    /** @return array{start: int, end: int, raw: string}|null */
    private function parseAngleDestination(string $markdown, string $masked, int $pos, int $length): ?array
    {
        $i = $pos + 1;
        while ($i < $length) {
            $char = $masked[$i];
            if ('\\' === $char && $i + 1 < $length) {
                $i += 2;
                continue;
            }
            if ('>' === $char) {
                return ['start' => $pos, 'end' => $i + 1, 'raw' => substr($markdown, $pos + 1, $i - $pos - 1)];
            }
            if ('<' === $char || "\n" === $char) {
                return null;
            }
            ++$i;
        }

        return null;
    }

    /** @return array{start: int, end: int, raw: string}|null */
    private function parseBareDestination(string $markdown, string $masked, int $pos, int $length): ?array
    {
        $i = $pos;
        $depth = 0;
        while ($i < $length) {
            $char = $masked[$i];
            if ('\\' === $char && $i + 1 < $length) {
                $i += 2;
                continue;
            }
            if ('(' === $char) {
                ++$depth;
                ++$i;
                continue;
            }
            if (')' === $char) {
                if (0 === $depth) {
                    break;
                }
                --$depth;
                ++$i;
                continue;
            }
            if (' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char) {
                break;
            }
            ++$i;
        }

        if ($i === $pos) {
            return null;
        }

        return ['start' => $pos, 'end' => $i, 'raw' => substr($markdown, $pos, $i - $pos)];
    }

    /** The position right after a valid title, or null if none starts here. */
    private function parseTitle(string $masked, int $pos, int $length): ?int
    {
        if ($pos >= $length) {
            return null;
        }

        $close = match ($masked[$pos]) {
            '"' => '"',
            "'" => "'",
            '(' => ')',
            default => null,
        };
        if (null === $close) {
            return null;
        }

        $i = $pos + 1;
        while ($i < $length) {
            $char = $masked[$i];
            if ('\\' === $char && $i + 1 < $length) {
                $i += 2;
                continue;
            }
            if ($char === $close) {
                return $i + 1;
            }
            ++$i;
        }

        return null;
    }

    private function skipWhitespace(string $text, int $pos, int $length): int
    {
        while ($pos < $length && \in_array($text[$pos], [' ', "\t", "\n", "\r"], true)) {
            ++$pos;
        }

        return $pos;
    }

    /** @param array{destStart: int, destEnd: int, raw: string, end: int} $parsed */
    private function buildReference(bool $isImage, array $parsed): ?MarkdownReference
    {
        $unescaped = $this->unescape($parsed['raw']);
        [$path, $fragment] = $this->splitFragment($unescaped);
        $decodedPath = rawurldecode($path);

        if (!$this->isLocalPath($decodedPath) || '' === $decodedPath) {
            return null;
        }

        $type = $isImage ? MarkdownReferenceType::Image : MarkdownReferenceType::Link;
        if (!$this->hasAllowedExtension($decodedPath, $type)) {
            return null;
        }

        return new MarkdownReference(
            $type,
            $decodedPath,
            $fragment,
            $parsed['destStart'],
            $parsed['destEnd'] - $parsed['destStart'],
        );
    }

    private function unescape(string $text): string
    {
        return preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $text) ?? $text;
    }

    /** @return array{0: string, 1: ?string} */
    private function splitFragment(string $target): array
    {
        $hashPos = strpos($target, '#');
        if (false === $hashPos) {
            return [$target, null];
        }

        return [substr($target, 0, $hashPos), substr($target, $hashPos)];
    }

    private function isLocalPath(string $path): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $path) !== 1;
    }

    private function hasAllowedExtension(string $path, MarkdownReferenceType $type): bool
    {
        if (MarkdownReferenceType::Image === $type) {
            $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

            return \in_array($extension, self::IMAGE_EXTENSIONS, true);
        }

        return DocumentExtension::isDocument($path);
    }

    /**
     * Blanks out (with NUL bytes, byte length preserved so offsets stay
     * valid) every fenced code block, indented code block and inline code
     * span, so the scan above never matches syntax inside one (ARC-10).
     */
    private function maskCodeRegions(string $markdown): string
    {
        $masked = $this->maskFencedCodeBlocks($markdown);
        $masked = $this->maskIndentedCodeBlocks($markdown, $masked);

        return $this->maskInlineCode($masked);
    }

    private function maskFencedCodeBlocks(string $markdown): string
    {
        $result = $markdown;
        $inFence = false;
        $fenceChar = '';
        $fenceLength = 0;
        $blockStart = 0;

        foreach ($this->splitLines($markdown) as [$start, $lineLength, $text]) {
            if (!$inFence) {
                if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $text, $match)) {
                    $inFence = true;
                    $fenceChar = $match[1][0];
                    $fenceLength = \strlen($match[1]);
                    $blockStart = $start;
                }
                continue;
            }

            $closePattern = '/^ {0,3}' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}\s*$/';
            if (preg_match($closePattern, $text)) {
                $inFence = false;
                $result = $this->maskRange($result, $blockStart, $start + $lineLength);
            }
        }

        if ($inFence) {
            $result = $this->maskRange($result, $blockStart, \strlen($markdown));
        }

        return $result;
    }

    private function maskIndentedCodeBlocks(string $markdown, string $masked): string
    {
        $result = $masked;
        $prevBlank = true;
        $inCodeBlock = false;
        // Inside a list, a line indented by 4 after a blank line is the
        // item's content (a paragraph, a nested item), not code. The list
        // ends at the first unindented line after a blank one that isn't a
        // new item. Real code nested in a list item is not detected: a
        // reference in it is read, the cheaper mistake.
        $inList = false;

        foreach ($this->splitLines($markdown) as [$start, $lineLength, $text]) {
            $maskedText = substr($result, $start, \strlen($text));
            if ('' !== $text && '' === trim($maskedText, "\x00")) {
                // Already masked (a fenced block): a structural break, not a blank line.
                $inCodeBlock = false;
                $prevBlank = false;
                continue;
            }

            if ('' === trim($text)) {
                $prevBlank = true;
                continue;
            }

            $isIndented = 1 === preg_match('/^(\t| {4,})/', $text);

            if (!$isIndented || !$inList) {
                $isListItem = 1 === preg_match('/^ {0,3}([-+*]|\d{1,9}[.)])([ \t]|$)/', $text);
                if ($isListItem) {
                    $inList = true;
                } elseif ($prevBlank && !preg_match('/^[ \t]/', $text)) {
                    $inList = false;
                }
            }

            if ($isIndented && !$inList && ($inCodeBlock || $prevBlank)) {
                $result = $this->maskRange($result, $start, $start + $lineLength);
                $inCodeBlock = true;
            } else {
                $inCodeBlock = false;
            }

            $prevBlank = false;
        }

        return $result;
    }

    private function maskInlineCode(string $masked): string
    {
        $result = $masked;
        foreach ($this->splitLines($masked) as [$start, , $text]) {
            $result = $this->maskInlineCodeInLine($result, $start, \strlen($text));
        }

        return $result;
    }

    private function maskInlineCodeInLine(string $result, int $lineStart, int $lineTextLength): string
    {
        $end = $lineStart + $lineTextLength;
        $i = $lineStart;

        while ($i < $end) {
            if ('`' !== $result[$i]) {
                ++$i;
                continue;
            }

            $runStart = $i;
            while ($i < $end && '`' === $result[$i]) {
                ++$i;
            }
            $runLength = $i - $runStart;

            $closeStart = $this->findBacktickRun($result, $i, $end, $runLength);
            if (null === $closeStart) {
                continue;
            }

            $closeEnd = $closeStart + $runLength;
            $result = $this->maskRange($result, $runStart, $closeEnd);
            $i = $closeEnd;
        }

        return $result;
    }

    private function findBacktickRun(string $text, int $from, int $end, int $runLength): ?int
    {
        $i = $from;
        while ($i < $end) {
            if ('`' !== $text[$i]) {
                ++$i;
                continue;
            }

            $start = $i;
            while ($i < $end && '`' === $text[$i]) {
                ++$i;
            }
            if ($i - $start === $runLength) {
                return $start;
            }
        }

        return null;
    }

    private function maskRange(string $text, int $start, int $end): string
    {
        for ($i = $start; $i < $end; ++$i) {
            if ("\n" !== $text[$i]) {
                $text[$i] = "\x00";
            }
        }

        return $text;
    }

    /** @return list<array{0: int, 1: int, 2: string}> [start, lineLengthIncludingTerminator, textWithoutTerminator] */
    private function splitLines(string $text): array
    {
        $lines = [];
        $length = \strlen($text);
        $start = 0;

        while ($start <= $length) {
            $newline = strpos($text, "\n", $start);
            if (false === $newline) {
                $line = substr($text, $start);
                $lines[] = [$start, \strlen($line), rtrim($line, "\r")];
                break;
            }

            $lineLength = $newline - $start + 1;
            $line = substr($text, $start, $newline - $start);
            $lines[] = [$start, $lineLength, rtrim($line, "\r")];
            $start = $newline + 1;
        }

        return $lines;
    }
}
