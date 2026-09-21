<?php

declare(strict_types=1);

namespace App\Enum\File;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum PathRefusal: string implements TranslatableInterface
{
    case NotFound = 'not_found';
    case Symlink = 'symlink';
    case NotAFile = 'not_a_file';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enums.path_refusal.' . $this->value, domain: 'enums', locale: $locale);
    }
}
