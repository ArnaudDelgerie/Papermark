<?php

declare(strict_types=1);

namespace App\Exception\Archive;

use App\Enum\Archive\ArchiveExportRefusalReason;
use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Thrown when an export can't proceed at all (see EDITOR_EXPORT.md, lot
 * 06-archive.md): `ext_img`/`ext_md` exists in the source folder as a plain
 * file instead of a directory, the plan has nothing to embark (ARC-01), or a
 * file-mode source isn't a document (ARC-12). The reason is the last segment
 * of the refusal's translation key: it is never shown alone, only through
 * this exception.
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class ArchiveExportRefusedException extends \RuntimeException implements UserFacingExceptionInterface
{
    public function __construct(public readonly ArchiveExportRefusalReason $reason)
    {
        parent::__construct(\sprintf('Archive export refused: %s.', $reason->value));
    }

    public function getTranslationKey(): string
    {
        return 'exceptions.archive.export_refused.' . $this->reason->value;
    }
}
