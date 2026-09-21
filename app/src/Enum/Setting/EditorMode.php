<?php

declare(strict_types=1);

namespace App\Enum\Setting;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum EditorMode: string implements TranslatableInterface
{
    case Single = 'single';
    case Dir = 'dir';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enums.editor_mode.' . $this->value, domain: 'enums', locale: $locale);
    }
}
