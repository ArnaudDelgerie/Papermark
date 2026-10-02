<?php

declare(strict_types=1);

namespace App\Enum\Archive;

/**
 * Why an archive was refused in full (see ArchiveImportRefusedException):
 * the value is the last segment of the refusal's translation key — it is
 * never displayed on its own.
 */
enum ArchiveImportRefusalReason: string
{
    case NotAZip = 'not_a_zip';
    case ZipSlip = 'zip_slip';
    case Symlink = 'symlink';
    case TooManyEntries = 'too_many_entries';
    case TooLarge = 'too_large';
    case Unreadable = 'unreadable';
    case Empty = 'empty';
}
