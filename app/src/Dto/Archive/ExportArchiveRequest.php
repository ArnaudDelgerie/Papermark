<?php

declare(strict_types=1);

namespace App\Dto\Archive;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of POST /archive/export. */
final readonly class ExportArchiveRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $source = '',
        #[Assert\NotBlank]
        public string $target = '',
        public bool $includeExternalMarkdown = false,
    ) {
    }
}
