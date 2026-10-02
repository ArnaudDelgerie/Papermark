<?php

declare(strict_types=1);

namespace App\Dto\Archive;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of POST /archive/import. */
final readonly class ImportArchiveRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $archive = '',
        #[Assert\NotBlank]
        public string $parentDir = '',
    ) {
    }
}
