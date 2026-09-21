<?php

declare(strict_types=1);

namespace App\Enum\File;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum NoReplaceRenameResult: string implements TranslatableInterface
{
    case Renamed = 'renamed';
    case TargetExists = 'target_exists';
    case Failed = 'failed';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enums.no_replace_rename_result.' . $this->value, domain: 'enums', locale: $locale);
    }
}
