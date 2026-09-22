<?php

declare(strict_types=1);

namespace App\Dto\Editor;

use App\Validator\Document\DocumentPath;

/** Body of POST /editor/file. */
final readonly class SetFileRequest
{
    public function __construct(
        #[DocumentPath]
        public string $path = '',
    ) {
    }
}
