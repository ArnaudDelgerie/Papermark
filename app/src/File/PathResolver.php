<?php

declare(strict_types=1);

namespace App\File;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Resolves a path against an anchor file — if the path isn't already
 * absolute, it's joined with dirname(anchor) — then validates the result
 * against a caller-supplied constraint (e.g. Symfony\Validator\Constraints\File).
 *
 * Generic on purpose: no mention of markdown or the editor, so it stays
 * reusable outside this feature. See EDITOR_IMAGES.md.
 */
final class PathResolver
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @return string|null the resolved, validated real path, or null if the
     *                      path can't be resolved or fails the constraint
     */
    public function resolve(string $path, ?string $anchor, Constraint $constraint): ?string
    {
        if (!$this->filesystem->isAbsolutePath($path)) {
            if ($anchor === null || $anchor === '') {
                return null;
            }

            $path = \dirname($anchor) . '/' . $path;
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            return null;
        }

        if (\count($this->validator->validate($realPath, $constraint)) > 0) {
            return null;
        }

        return $realPath;
    }
}
