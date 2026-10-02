<?php

declare(strict_types=1);

namespace App\Event\Document;

/**
 * Dispatched by DocumentStore::save after every successful write, including
 * one that wrote nothing because the content was already on disk (FIL-11).
 */
final class DocumentSaved
{
    public function __construct(
        public readonly string $path,
        public readonly string $revision,
    ) {
    }
}
