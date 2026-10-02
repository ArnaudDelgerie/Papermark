<?php

declare(strict_types=1);

namespace App\Dto\Document;

use App\Validator\Document\DocumentPath;

/** Body of POST /document/delete. */
final readonly class DeleteDocumentRequest
{
    public function __construct(
        #[DocumentPath]
        public string $path = '',
    ) {
    }
}
