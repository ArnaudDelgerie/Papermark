<?php

declare(strict_types=1);

namespace App\Dto\Editor;

use App\Enum\Setting\EditorMode;
use Symfony\Component\Validator\Constraints as Assert;

/** Body of POST /editor/mode. An unknown value fails the binding itself, no tryFrom needed. */
final readonly class SetModeRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?EditorMode $mode = null,
    ) {
    }
}
