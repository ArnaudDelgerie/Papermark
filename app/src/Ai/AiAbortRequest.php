<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /ai/abort.
 */
final readonly class AiAbortRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $id,
    ) {
    }
}
