<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\MarkdownReference;
use App\Enum\DocumentExtension;
use App\Enum\MarkdownReferenceType;

/**
 * Finds the image and local-document references inside a markdown document,
 * for the export archive feature (see EDITOR_EXPORT.md).
 *
 * A reference is only recognised when it points to a known extension:
 * IMAGE_EXTENSIONS for images `![]()`, DocumentExtension for links `[]()` to
 * another document. Same local-path rule as DocumentCodec: a scheme (http:,
 * data:, …) means an external reference, left alone — as is an `<img>` tag,
 * which neither pattern matches. A link's `#anchor`, if any, is split off
 * into MarkdownReference::$fragment so a rewriter can reattach it to the
 * rewritten path.
 *
 * IMAGE_PATTERN is public: DocumentCodec reuses it rather than keeping its
 * own copy of the same regex (lot 03-services-document.md).
 */
final class MarkdownReferenceScanner
{
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'];

    public const IMAGE_PATTERN = '/!\[([^\]]*)\]\(([^)\s]+)(\s+"[^"]*")?\)/';
    private const LINK_PATTERN = '/(?<!!)\[([^\]]*)\]\(([^)\s]+)(\s+"[^"]*")?\)/';

    /** @return MarkdownReference[] */
    public function find(string $markdown): array
    {
        return [
            ...$this->findMatches($markdown, self::IMAGE_PATTERN, MarkdownReferenceType::Image),
            ...$this->findMatches($markdown, self::LINK_PATTERN, MarkdownReferenceType::Link),
        ];
    }

    /** @return MarkdownReference[] */
    private function findMatches(string $markdown, string $pattern, MarkdownReferenceType $type): array
    {
        preg_match_all($pattern, $markdown, $matches, \PREG_SET_ORDER);

        $references = [];
        foreach ($matches as $match) {
            $target = $match[2];

            if (!$this->isLocalPath($target)) {
                continue;
            }

            [$path, $fragment] = $this->splitFragment($target);

            if ($path === '' || !$this->hasAllowedExtension($path, $type)) {
                continue;
            }

            $references[] = new MarkdownReference($type, $match[0], $path, $fragment);
        }

        return $references;
    }

    private function isLocalPath(string $path): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $path) !== 1;
    }

    /** @return array{0: string, 1: ?string} */
    private function splitFragment(string $target): array
    {
        $hashPos = strpos($target, '#');
        if ($hashPos === false) {
            return [$target, null];
        }

        return [substr($target, 0, $hashPos), substr($target, $hashPos)];
    }

    private function hasAllowedExtension(string $path, MarkdownReferenceType $type): bool
    {
        if ($type === MarkdownReferenceType::Image) {
            $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

            return \in_array($extension, self::IMAGE_EXTENSIONS, true);
        }

        return DocumentExtension::isDocument($path);
    }
}
