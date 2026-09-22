<?php

declare(strict_types=1);

namespace App\Exception\Filesystem;

use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(Response::HTTP_INTERNAL_SERVER_ERROR)]
final class WriteFailedException extends \RuntimeException implements UserFacingExceptionInterface
{
    public function __construct(public readonly string $path)
    {
        parent::__construct(\sprintf('Could not write "%s".', $path));
    }

    public function getTranslationKey(): string
    {
        return 'exceptions.file.write_failed';
    }
}
