<?php

declare(strict_types=1);

namespace App\Exception\Ai;

use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(Response::HTTP_INTERNAL_SERVER_ERROR)]
final class ApiKeySaveFailedException extends \RuntimeException implements UserFacingExceptionInterface
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct(\sprintf('Could not save the API key of "%s".', $provider));
    }

    public function getTranslationKey(): string
    {
        return 'exceptions.ai.key_save_failed';
    }
}
