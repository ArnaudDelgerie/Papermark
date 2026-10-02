<?php

declare(strict_types=1);

namespace App\Exception\Document;

use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * A file whose bytes are not valid UTF-8: the editor could never show it, a
 * later read would fail the JSON encoding. Refused, never converted — and
 * the state stays as it is, so the file shown before keeps opening after a
 * reload (lot 03-enregistrement.md, FIL-09).
 */
#[WithHttpStatus(Response::HTTP_FORBIDDEN)]
final class DocumentNotUtf8Exception extends \RuntimeException implements UserFacingExceptionInterface
{
    public function __construct(public readonly string $path)
    {
        parent::__construct(\sprintf('File "%s" is not UTF-8 encoded.', $path));
    }

    public function getTranslationKey(): string
    {
        return 'exceptions.document.not_utf8';
    }
}
