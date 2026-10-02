<?php

declare(strict_types=1);

namespace App\Enum\Archive;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ExportIssueReason: string implements TranslatableInterface
{
    case NotFound = 'not_found';
    case LimitExceeded = 'limit_exceeded';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enums.export_issue_reason.' . $this->value, domain: 'enums', locale: $locale);
    }
}
