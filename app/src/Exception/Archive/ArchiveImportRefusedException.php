<?php

declare(strict_types=1);

namespace App\Exception\Archive;

use App\Enum\Archive\ArchiveImportRefusalReason;
use App\Interface\UserFacingExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Thrown when any single entry of the archive makes the whole import unsafe
 * or too large (see EDITOR_IMPORT.md, "Garde-fous") — the archive is refused
 * in full, never partially extracted. The reason is the last segment of the
 * refusal's translation key: it is never shown alone, only through this
 * exception.
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class ArchiveImportRefusedException extends \RuntimeException implements UserFacingExceptionInterface
{
    public function __construct(public readonly ArchiveImportRefusalReason $reason)
    {
        parent::__construct(\sprintf('Archive import refused: %s.', $reason->value));
    }

    public function getTranslationKey(): string
    {
        return 'exceptions.archive.import_refused.' . $this->reason->value;
    }
}
