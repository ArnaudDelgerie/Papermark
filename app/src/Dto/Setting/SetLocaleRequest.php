<?php

declare(strict_types=1);

namespace App\Dto\Setting;

use App\Enum\Setting\AppLocale;
use Symfony\Component\Validator\Constraints as Assert;

/** Body of POST /settings/locale. An unknown value fails the binding itself, no tryFrom needed. */
final readonly class SetLocaleRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?AppLocale $locale = null,
    ) {
    }
}
