<?php

declare(strict_types=1);

namespace App\Dto\Editor;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /editor/open. Whether the path exists, and whether it is a
 * folder or a file, is the server's call (lot 04a), not this DTO's.
 */
final readonly class OpenPathRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $path = '',
    ) {
    }
}
