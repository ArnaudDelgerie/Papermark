<?php

declare(strict_types=1);

namespace App\Import;

enum ArchiveImportRefusalReason: string
{
    case NotAZip = 'not_a_zip';
    case ZipSlip = 'zip_slip';
    case Symlink = 'symlink';
    case TooManyEntries = 'too_many_entries';
    case TooLarge = 'too_large';
}
