<?php

declare(strict_types=1);

namespace App\Exception\Filesystem;

use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class RenameTargetExistsException extends \RuntimeException implements UserFacingExceptionInterface
{
    public function __construct(public readonly string $target)
    {
        parent::__construct(\sprintf('Rename target "%s" already exists.', $target));
    }

    public function getTranslationKey(): string
    {
        return 'exceptions.file.rename_target_exists';
    }
}
