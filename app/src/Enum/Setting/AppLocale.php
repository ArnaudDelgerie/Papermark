<?php

declare(strict_types=1);

namespace App\Enum\Setting;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum AppLocale: string implements TranslatableInterface
{
    case En = 'en';
    case Fr = 'fr';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enums.app_locale.' . $this->value, domain: 'enums', locale: $locale);
    }
}
