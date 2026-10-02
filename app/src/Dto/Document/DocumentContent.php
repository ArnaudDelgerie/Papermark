<?php

declare(strict_types=1);

namespace App\Dto\Document;

/**
 * What DocumentStore::read returns: the content already converted for the
 * editor, and the revision it is hashed from (lot 03-services-document.md).
 */
final class DocumentContent
{
    public function __construct(
        public readonly string $path,
        public readonly string $content,
        public readonly string $revision,
    ) {
    }
}
