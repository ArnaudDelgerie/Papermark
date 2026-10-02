<?php

declare(strict_types=1);

namespace App\Exception\Document;

use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * A save carrying the revision it read, answered by a file that changed
 * outside of Papermark meanwhile: nothing is written, the caller asks
 * Save as or Overwrite (lot 03-enregistrement.md, FIL-03).
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class DocumentModifiedException extends \RuntimeException implements UserFacingExceptionInterface
{
    public function __construct(public readonly string $path)
    {
        parent::__construct(\sprintf('File "%s" was modified since it was read.', $path));
    }

    public function getTranslationKey(): string
    {
        return 'exceptions.document.modified';
    }
}
