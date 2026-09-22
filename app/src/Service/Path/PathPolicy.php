<?php

declare(strict_types=1);

namespace App\Service\Path;

use App\Exception\Path\PathIsSymlinkException;
use App\Exception\Path\PathNotAFileException;
use App\Exception\Path\PathNotFoundException;
use Symfony\Component\Validator\Constraint;

/**
 * The one place that decides whether the app may touch a path, and how (see
 * .project/lots/02-chemins.md). PathResolver stays the mechanical half —
 * joining a relative path to an anchor, realpath, validating a constraint —
 * while this service owns the policy on top of it: the write intentions see
 * the path itself with lstat(), never through realpath() alone, so a
 * symbolic link is refused before it is followed.
 *
 * Reading follows links: opening a linked .md is a normal use. Writing
 * never does: save, delete and rename must act on what the user sees, not
 * on whatever the link points to. Those three refuse with an exception.
 */
final class PathPolicy
{
    public function __construct(
        private readonly PathResolver $pathResolver,
    ) {
    }

    /**
     * Intention read: an existing, readable file. `$anchor` resolves a
     * relative `$path`, `$constraint` is validated on the resolved path —
     * both optional, a picked path is absolute and opens as-is.
     *
     * @return string|null the resolved, validated real path, or null if
     *                     the path can't be resolved or isn't readable
     */
    public function read(string $path, ?string $anchor = null, ?Constraint $constraint = null): ?string
    {
        $realPath = $this->pathResolver->resolve($path, $anchor, $constraint);
        if ($realPath === null || !is_file($realPath) || !is_readable($realPath)) {
            return null;
        }

        return $realPath;
    }

    /**
     * Intention save: a file that may not exist yet. The parent must resolve
     * to a directory; an existing path must be a regular file and never a
     * symbolic link — whatever the link points to is not what was asked for.
     *
     * @return string the canonical path
     *
     * @throws PathNotFoundException  the parent can't be resolved
     * @throws PathIsSymlinkException
     * @throws PathNotAFileException
     */
    public function save(string $path): string
    {
        return $this->writable($path);
    }

    /**
     * Intention delete: an existing regular file, never a symbolic link.
     *
     * @return string the canonical path
     *
     * @throws PathNotFoundException
     * @throws PathIsSymlinkException
     * @throws PathNotAFileException
     */
    public function delete(string $path): string
    {
        return $this->existingWritable($path);
    }

    /**
     * Intention rename: the file being renamed — the same rules as delete.
     * The target is not checked here: NoReplaceRename refuses a taken one
     * atomically.
     *
     * @return string the canonical path
     *
     * @throws PathNotFoundException
     * @throws PathIsSymlinkException
     * @throws PathNotAFileException
     */
    public function rename(string $path): string
    {
        return $this->existingWritable($path);
    }

    /**
     * Intention list: an existing directory.
     *
     * @return string|null the resolved real path, or null if there is no
     *                     directory there
     */
    public function list(string $path): ?string
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) {
            return null;
        }

        return $realPath;
    }

    private function writable(string $path): string
    {
        $parent = realpath(\dirname($path));
        if ($parent === false || !is_dir($parent)) {
            throw new PathNotFoundException($path);
        }

        $canonical = $parent . '/' . basename($path);
        if (is_link($canonical)) {
            throw new PathIsSymlinkException($canonical);
        }

        if (file_exists($canonical) && !is_file($canonical)) {
            throw new PathNotAFileException($canonical);
        }

        return $canonical;
    }

    private function existingWritable(string $path): string
    {
        $canonical = $this->writable($path);
        if (!is_file($canonical)) {
            throw new PathNotFoundException($canonical);
        }

        return $canonical;
    }
}
