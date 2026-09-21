<?php

declare(strict_types=1);

namespace App\Exception\File;

use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(Response::HTTP_FORBIDDEN)]
final class PathIsSymlinkException extends \RuntimeException implements UserFacingExceptionInterface
{
    public function __construct(public readonly string $path)
    {
        parent::__construct(\sprintf('Path "%s" is a symbolic link.', $path));
    }

    public function getTranslationKey(): string
    {
        return 'exceptions.file.path_is_symlink';
    }
}
