<?php

declare(strict_types=1);

namespace App\Validator\Document;

use Symfony\Component\Validator\Constraint;

/**
 * The new name of a document (lot 04-document-controller.md): non-empty, a
 * single path segment (`basename`), with an extension `DocumentExtension`
 * recognizes.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class DocumentName extends Constraint
{
    public string $invalidMessage = 'document.name.invalid';
    public string $extensionMessage = 'document.name.unsupported_extension';
}
