<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /ai/instruct. The provider and model are not part of it: the
 * worker reads them from the database.
 */
final readonly class AiInstructRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $id,
        #[Assert\NotBlank]
        public string $instruction,
        public string $document = '',
        public string $selection = '',
    ) {
    }
}
