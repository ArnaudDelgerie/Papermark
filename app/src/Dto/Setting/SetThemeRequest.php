<?php

declare(strict_types=1);

namespace App\Dto\Setting;

use App\Enum\Setting\ThemeMode;
use Symfony\Component\Validator\Constraints as Assert;

/** Body of POST /settings/theme. An unknown value fails the binding itself, no tryFrom needed. */
final readonly class SetThemeRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?ThemeMode $theme = null,
    ) {
    }
}
