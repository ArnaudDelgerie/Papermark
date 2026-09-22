<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * A server-rendered form refused a submission: unlike
 * UserFacingExceptionInterface, which carries one translation key, a
 * refused form carries several messages, already translated and grouped by
 * field. Belongs to no domain: any form could refuse this way.
 */
#[WithHttpStatus(Response::HTTP_UNPROCESSABLE_ENTITY)]
final class FormRefusedException extends \RuntimeException
{
    /**
     * @param list<string> $genericErrors messages, already translated
     * @param list<array{field: string, message: string}> $mappedErrors each bound to a field, already translated
     */
    public function __construct(
        public readonly array $genericErrors,
        public readonly array $mappedErrors,
    ) {
        parent::__construct('Form refused.');
    }
}
