<?php

declare(strict_types=1);

namespace App\File;

use App\Enum\File\PathRefusal;
use Symfony\Component\Validator\Constraint;

/**
 * The one place that decides whether the app may touch a path, and how (see
 * .project/lots/02-chemins.md). PathResolver stays the mechanical half —
 * joining a relative path to an anchor, realpath, validating a constraint —
 * while this service owns the policy on top of it: the write intention sees
 * the path itself with lstat(), never through realpath() alone, so a
 * symbolic link is refused before it is followed.
 *
 * Reading follows links: opening a linked .md is a normal use. Writing
 * never does: save, delete and rename must act on what the user sees, not
 * on whatever the link points to.
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
     * Intention write: a file that may not exist yet (a save, later the
     * target of an archive import). The parent must resolve to a directory;
     * an existing path must be a regular file and never a symbolic link —
     * whatever the link points to is not what was asked for.
     */
    public function write(string $path): PathPermission
    {
        $parent = realpath(\dirname($path));
        if ($parent === false || !is_dir($parent)) {
            return PathPermission::refused(PathRefusal::NotFound);
        }

        $canonical = $parent . '/' . basename($path);
        if (is_link($canonical)) {
            return PathPermission::refused(PathRefusal::Symlink);
        }

        if (file_exists($canonical) && !is_file($canonical)) {
            return PathPermission::refused(PathRefusal::NotAFile);
        }

        return PathPermission::allowed($canonical);
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
}
