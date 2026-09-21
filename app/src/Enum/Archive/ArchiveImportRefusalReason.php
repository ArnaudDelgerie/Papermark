<?php

declare(strict_types=1);

namespace App\Enum\Archive;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ArchiveImportRefusalReason: string implements TranslatableInterface
{
    case NotAZip = 'not_a_zip';
    case ZipSlip = 'zip_slip';
    case Symlink = 'symlink';
    case TooManyEntries = 'too_many_entries';
    case TooLarge = 'too_large';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enums.archive_import_refusal_reason.' . $this->value, domain: 'enums', locale: $locale);
    }
}
