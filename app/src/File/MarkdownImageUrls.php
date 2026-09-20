<?php

declare(strict_types=1);

namespace App\File;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Rewrites markdown image references between the raw path written on disk
 * and the /file/image service URL the editor displays (see
 * EDITOR_IMAGES.md): toServiceUrls() runs when a document is opened,
 * toRawPaths() runs just before its markdown leaves the editor (save, copy).
 *
 * Only local-looking paths are touched: a scheme (http:, data:, …) means an
 * external image, which this app doesn't manage — left untouched.
 */
final class MarkdownImageUrls
{
    private const IMAGE_PATTERN = '/!\[([^\]]*)\]\(([^)\s]+)(\s+"[^"]*")?\)/';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function toServiceUrls(string $markdown): string
    {
        return preg_replace_callback(
            self::IMAGE_PATTERN,
            function (array $match): string {
                [, $alt, $path, $title] = $match + [2 => '', 3 => ''];

                if (!$this->isLocalPath($path)) {
                    return $match[0];
                }

                // The URL carries the path alone: the anchor that resolves a
                // relative one lives in the session, not in the markdown
                // (lot 02-chemins.md, SEC-04).
                $url = $this->urlGenerator->generate('app_file_image', ['path' => $path]);

                return "![{$alt}]({$url}{$title})";
            },
            $markdown,
        ) ?? $markdown;
    }

    public function toRawPaths(string $markdown): string
    {
        return preg_replace_callback(
            self::IMAGE_PATTERN,
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
        $prefix = '/file/image?';
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
}
