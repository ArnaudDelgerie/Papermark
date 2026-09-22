<?php

declare(strict_types=1);

namespace App\Enum\Setting;

use App\Trait\EnumLabelsTrait;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ThemeMode: string implements TranslatableInterface
{
    use EnumLabelsTrait;

    // The cycle order: Auto -> Light -> Dark -> Auto.
    case Auto = 'auto';
    case Light = 'light';
    case Dark = 'dark';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enums.theme_mode.' . $this->value, domain: 'enums', locale: $locale);
    }
}
