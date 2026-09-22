<?php

declare(strict_types=1);

namespace App\Exception\Path;

use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(Response::HTTP_NOT_FOUND)]
final class PathNotFoundException extends \RuntimeException implements UserFacingExceptionInterface
{
    public function __construct(public readonly string $path)
    {
        parent::__construct(\sprintf('Path "%s" was not found.', $path));
    }

    public function getTranslationKey(): string
    {
        return 'exceptions.path.not_found';
    }
}
