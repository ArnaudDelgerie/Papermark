<?php

declare(strict_types=1);

namespace App\Validator\Document;

use Symfony\Component\Validator\Constraint;

/**
 * A document path (lot 04-document-controller.md): non-empty, with an
 * extension `DocumentExtension` recognizes. A path picked by hand never
 * comes back blank, so empty and missing are refused the same way.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class DocumentPath extends Constraint
{
    public string $blankMessage = 'document.path.blank';
    public string $extensionMessage = 'document.path.unsupported_extension';
}
