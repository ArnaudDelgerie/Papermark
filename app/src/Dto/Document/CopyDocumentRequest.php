<?php

declare(strict_types=1);

namespace App\Dto\Document;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of POST /document/copy. */
final readonly class CopyDocumentRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?string $content = null,
    ) {
    }
}
