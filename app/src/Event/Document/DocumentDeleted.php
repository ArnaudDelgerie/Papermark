<?php

declare(strict_types=1);

namespace App\Event\Document;

/** Dispatched by DocumentStore::delete after the file is gone. */
final class DocumentDeleted
{
    public function __construct(
        public readonly string $path,
    ) {
    }
}
