<?php

declare(strict_types=1);

namespace App\Dto\Editor;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of POST /editor/dir. Whether the directory exists is PathPolicy's call, not this DTO's. */
final readonly class SetDirRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $path = '',
    ) {
    }
}
