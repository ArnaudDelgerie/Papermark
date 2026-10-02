<?php

declare(strict_types=1);

namespace App\Exception\Path;

use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * A write intention (save, export target, import destination) whose folder,
 * or existing file, is not writable. rename() only asks the folder: without
 * this control the atomic write would make a read-only file replaceable.
 */
#[WithHttpStatus(Response::HTTP_FORBIDDEN)]
final class PathNotWritableException extends \RuntimeException implements UserFacingExceptionInterface
{
    public function __construct(public readonly string $path)
    {
        parent::__construct(\sprintf('Path "%s" is not writable.', $path));
    }

    public function getTranslationKey(): string
    {
        return 'exceptions.path.not_writable';
    }
}
