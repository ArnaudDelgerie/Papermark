<?php

declare(strict_types=1);

namespace App\Exception\Document;

use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * A save carrying the revision it read, answered by a file that disappeared
 * since: nothing is written (lot 03-enregistrement.md, FIL-03).
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class DocumentGoneException extends \RuntimeException implements UserFacingExceptionInterface
{
    public function __construct(public readonly string $path)
    {
        parent::__construct(\sprintf('File "%s" no longer exists on disk.', $path));
    }

    public function getTranslationKey(): string
    {
        return 'exceptions.document.gone';
    }
}
