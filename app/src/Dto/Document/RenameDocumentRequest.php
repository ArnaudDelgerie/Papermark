<?php

declare(strict_types=1);

namespace App\Dto\Document;

use App\Validator\Document\DocumentName;
use App\Validator\Document\DocumentPath;

/** Body of POST /document/rename. */
final readonly class RenameDocumentRequest
{
    public function __construct(
        #[DocumentPath]
        public string $path = '',
        #[DocumentName]
        public string $name = '',
    ) {
    }
}
