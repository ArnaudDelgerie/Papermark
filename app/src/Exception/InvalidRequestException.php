<?php

declare(strict_types=1);

namespace App\Exception;

use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * An input the route cannot accept: absent, malformed, or a value outside
 * the known ones. The checks stay hand-written in the controllers until the
 * request DTOs of lots 3 to 5 replace them — this exception carries the
 * refusal's short name, which is also its translation key's last segment.
 */
#[WithHttpStatus(Response::HTTP_UNPROCESSABLE_ENTITY)]
final class InvalidRequestException extends \RuntimeException implements UserFacingExceptionInterface
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(\sprintf('Invalid request: %s.', $reason));
    }

    public function getTranslationKey(): string
    {
        return 'exceptions.request.' . $this->reason;
    }
}
