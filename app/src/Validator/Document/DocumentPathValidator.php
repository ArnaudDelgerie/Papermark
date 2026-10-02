<?php

declare(strict_types=1);

namespace App\Validator\Document;

use App\Enum\DocumentExtension;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class DocumentPathValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof DocumentPath) {
            throw new UnexpectedTypeException($constraint, DocumentPath::class);
        }

        if (!\is_string($value) || $value === '') {
            $this->context->buildViolation($constraint->blankMessage)->addViolation();

            return;
        }

        if (!DocumentExtension::isDocument($value)) {
            $this->context->buildViolation($constraint->extensionMessage)->addViolation();
        }
    }
}
