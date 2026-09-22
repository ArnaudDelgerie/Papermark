<?php

declare(strict_types=1);

namespace App\Dto\Document;

use App\Validator\Document\DocumentPath;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /document/save. `revision` absent or empty means no
 * control: Save as, or Écraser after a conflict (lot 03-services-document.md).
 */
final readonly class SaveDocumentRequest
{
    public function __construct(
        #[DocumentPath]
        public string $path = '',
        #[Assert\NotNull]
        public ?string $content = null,
        public ?string $revision = null,
    ) {
    }
}
