<?php

declare(strict_types=1);

namespace App\Enum\File;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum MarkdownReferenceType: string implements TranslatableInterface
{
    case Image = 'image';
    case Link = 'link';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enums.markdown_reference_type.' . $this->value, domain: 'enums', locale: $locale);
    }
}
