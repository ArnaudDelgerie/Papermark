<?php

declare(strict_types=1);

namespace App\File;

use App\Editor\EditorState;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;

/**
 * The .md tree of the current folder of the EditorState, or null when there
 * is none. Sits between EditorState and DirectoryTree so that the caller —
 * today EditorController::getDir, inside the sidebar frame — doesn't have to
 * know about either (see EDITOR_FOLDER_MODE.md).
 *
 * The walk costs most of a tree request on a big folder, so its result — the
 * list of .md files of the current folder — is cached: one entry, the folder
 * it belongs to alongside. The in-app writes (save, delete, rename) update it
 * in place; changing folder or the refresh button forget it, and the next
 * build() walks again. A file added or removed outside the app only shows
 * after one of those two (EDITOR_REACTIVITY.md).
 */
final readonly class OpenDirectoryTree
{
    private const CACHE_KEY = 'editor.dir_tree';

    public function __construct(
        private EditorState $editorState,
        private DirectoryTree $directoryTree,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function build(): ?DirectoryTreeResult
    {
        $directory = $this->editorState->getDir();
        if ($directory === null) {
            return null;
        }

        if (!is_dir($directory)) {
            // The session held a path that no longer exists (moved, unmounted…).
            $this->forget();

            return null;
        }

        $listing = $this->listing();
        if ($listing === null || $listing['root'] !== $directory) {
            try {
                $paths = $this->directoryTree->scan($directory);
            } catch (DirectoryNotFoundException) {
                return null;
            }
            $listing = ['root' => $directory, 'paths' => $paths === null ? null : array_fill_keys($paths, true)];
            $this->save($listing);
        }

        return $listing['paths'] === null
            ? DirectoryTreeResult::tooLarge()
            : $this->directoryTree->fromPaths($directory, array_map('strval', array_keys($listing['paths'])));
    }

    /** The next build() walks the folder again. */
    public function forget(): void
    {
        $this->cache->deleteItem(self::CACHE_KEY);
    }

    public function fileAdded(string $path): void
    {
        $this->update($path, true);
    }

    public function fileRemoved(string $path): void
    {
        $this->update($path, false);
    }

    /** A rename stays in the same folder, but the new name may not be listed. */
    public function fileRenamed(string $oldPath, string $newPath): void
    {
        $this->update($oldPath, false);
        $this->update($newPath, true);
    }

    /**
     * `$path` is realpath'd by the caller, as is the cached root: a plain
     * prefix tells whether it is in the folder. Nothing to do past the
     * traversal cap, the tree isn't shown.
     */
    private function update(string $path, bool $present): void
    {
        $listing = $this->listing();
        if ($listing === null || $listing['paths'] === null || !DirectoryTree::isListed($path)) {
            return;
        }

        $prefix = rtrim($listing['root'], '/') . '/';
        if (!str_starts_with($path, $prefix)) {
            return;
        }

        $relativePath = substr($path, \strlen($prefix));
        if ($present) {
            $listing['paths'][$relativePath] = true;
        } else {
            unset($listing['paths'][$relativePath]);
        }
        $this->save($listing);
    }

    /**
     * @return array{root: string, paths: array<string, true>|null}|null
     */
    private function listing(): ?array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);

        return $item->isHit() ? $item->get() : null;
    }

    /**
     * @param array{root: string, paths: array<string, true>|null} $listing
     */
    private function save(array $listing): void
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        $this->cache->save($item->set($listing));
    }
}
