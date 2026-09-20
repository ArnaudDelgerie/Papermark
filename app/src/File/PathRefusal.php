<?php

declare(strict_types=1);

namespace App\File;

/**
 * Why a write intention was refused, for the caller to answer with the
 * error that fits its route (lot 02-chemins.md).
 */
enum PathRefusal
{
    /** Nothing at the path, or its parent directory can't be resolved. */
    case NotFound;

    /** The path itself is a symbolic link: fine to read through, never to act on. */
    case Symlink;

    /** The path exists but is not a regular file. */
    case NotAFile;
}
