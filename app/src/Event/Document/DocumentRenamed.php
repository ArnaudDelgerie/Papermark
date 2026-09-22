<?php

declare(strict_types=1);

namespace App\Event\Document;

/** Dispatched by DocumentStore::rename once the file is moved. */
final class DocumentRenamed
{
    public function __construct(
        public readonly string $oldPath,
        public readonly string $newPath,
    ) {
    }
}
