<?php

declare(strict_types=1);

namespace App\Service\Directory;

use App\Dto\Directory\DirectoryFile;
use App\Dto\Directory\DirectoryNode;
use App\Dto\Directory\DirectoryTreeResult;
use App\Enum\DocumentExtension;
use Symfony\Component\Finder\Finder;

/**
 * Builds the dir-mode tree: only directories that hold a document (see
 * DocumentExtension) at some depth, hidden files and directories included.
 * The whole subtree has to be
 * walked to know that (see EDITOR_FOLDER_MODE.md), so a traversal cap guards
 * against a pathological directory (the whole filesystem, a symlink cycle —
 * though Finder doesn't follow symlinks by default). Measured on this repo,
 * walking ~49k entries (incl. vendor/ and node_modules/) takes ~135ms: local
 * stat/readdir is cheap, so the cap only needs to catch truly extreme cases,
 * not ordinary project directories.
 *
 * ignoreVCSIgnored() was tried and reverted: besides not reducing the actual
 * walk (a post-hoc filter, same as name() would be), .gitignore answers "commit
 * or not", not "is this noise" — it silently hid real content (this repo's own
 * root .gitignore excludes .project/, where these design docs live).
 */
final class DirectoryTree
{
    private const DEFAULT_MAX_ITEMS = 200000;

    public function __construct(
        private readonly int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {
    }

    public function build(string $root): DirectoryTreeResult
    {
        $root = rtrim($root, '/');
        $relativePaths = $this->scan($root);

        return $relativePaths === null ? DirectoryTreeResult::tooLarge() : $this->fromPaths($root, $relativePaths);
    }

    /**
     * The walk: the documents below `$root`, relative to it and '/'-separated,
     * or null past the traversal cap. This is the costly part, kept apart so
     * that OpenDirectoryTree can cache its result.
     *
     * @return string[]|null
     */
    public function scan(string $root): ?array
    {
        $finder = new Finder();
        $finder->in(rtrim($root, '/'))->ignoreDotFiles(false)->ignoreVCS(false);

        $mdFiles = [];
        $count = 0;
        foreach ($finder as $fileInfo) {
            if (++$count > $this->maxItems) {
                return null;
            }

            if ($fileInfo->isFile() && DocumentExtension::isDocument($fileInfo->getFilename())) {
                $mdFiles[] = $fileInfo->getRelativePathname();
            }
        }

        return $mdFiles;
    }

    /**
     * @param string[] $relativePaths as scan() returns them
     */
    public function fromPaths(string $root, array $relativePaths): DirectoryTreeResult
    {
        $root = rtrim($root, '/');

        return DirectoryTreeResult::ok($this->buildNode(basename($root), $root, $relativePaths));
    }

    /**
     * @param string[] $relativePaths .md files below this node, relative to it, '/'-separated
     */
    private function buildNode(string $name, string $path, array $relativePaths): DirectoryNode
    {
        $filesHere = [];
        $childRelativePaths = [];

        foreach ($relativePaths as $relativePath) {
            $segments = explode('/', $relativePath);
            $fileName = array_pop($segments);

            if ($segments === []) {
                $filesHere[] = new DirectoryFile($fileName, $path . '/' . $fileName, is_link($path . '/' . $fileName));
                continue;
            }

            $childName = array_shift($segments);
            $childRelativePaths[$childName][] = implode('/', [...$segments, $fileName]);
        }

        $directories = [];
        foreach ($childRelativePaths as $childName => $paths) {
            $directories[] = $this->buildNode($childName, $path . '/' . $childName, $paths);
        }

        usort($directories, static fn (DirectoryNode $a, DirectoryNode $b): int => $a->name <=> $b->name);
        usort($filesHere, static fn (DirectoryFile $a, DirectoryFile $b): int => $a->name <=> $b->name);

        return new DirectoryNode($name, $path, $directories, $filesHere);
    }
}
