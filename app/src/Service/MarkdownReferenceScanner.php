<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\MarkdownReference;
use App\Enum\MarkdownReferenceType;

/**
 * Finds the image and local-document references inside a markdown document,
 * for the export archive feature (see EDITOR_EXPORT.md).
 *
 * A reference is only recognised when it points to a known extension:
 * IMAGE_EXTENSIONS for images `![]()`, DOCUMENT_EXTENSIONS for links `[]()`
 * to another .md/.markdown/.txt file. Same local-path rule as
 * MarkdownImageUrls: a scheme (http:, data:, …) means an external reference,
 * left alone — as is an `<img>` tag, which neither pattern matches. A link's
 * `#anchor`, if any, is split off into MarkdownReference::$fragment so a
 * rewriter can reattach it to the rewritten path.
 */
final class MarkdownReferenceScanner
{
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'];
    public const DOCUMENT_EXTENSIONS = ['md', 'markdown', 'txt'];

    private const IMAGE_PATTERN = '/!\[([^\]]*)\]\(([^)\s]+)(\s+"[^"]*")?\)/';
    private const LINK_PATTERN = '/(?<!!)\[([^\]]*)\]\(([^)\s]+)(\s+"[^"]*")?\)/';

    /** @return MarkdownReference[] */
    public function find(string $markdown): array
    {
        return [
            ...$this->findMatches($markdown, self::IMAGE_PATTERN, MarkdownReferenceType::Image, self::IMAGE_EXTENSIONS),
            ...$this->findMatches($markdown, self::LINK_PATTERN, MarkdownReferenceType::Link, self::DOCUMENT_EXTENSIONS),
        ];
    }

    /**
     * @param string[] $allowedExtensions
     *
     * @return MarkdownReference[]
     */
    private function findMatches(string $markdown, string $pattern, MarkdownReferenceType $type, array $allowedExtensions): array
    {
        preg_match_all($pattern, $markdown, $matches, \PREG_SET_ORDER);

        $references = [];
        foreach ($matches as $match) {
            $target = $match[2];

            if (!$this->isLocalPath($target)) {
                continue;
            }

            [$path, $fragment] = $this->splitFragment($target);

            if ($path === '' || !$this->hasAllowedExtension($path, $allowedExtensions)) {
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

    /** @param string[] $allowedExtensions */
    private function hasAllowedExtension(string $path, array $allowedExtensions): bool
    {
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return \in_array($extension, $allowedExtensions, true);
    }
}
