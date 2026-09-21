<?php

declare(strict_types=1);

namespace App\Enum\Setting;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ThemeMode: string implements TranslatableInterface
{
    case Light = 'light';
    case Dark = 'dark';
    case Auto = 'auto';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enums.theme_mode.' . $this->value, domain: 'enums', locale: $locale);
    }
}
