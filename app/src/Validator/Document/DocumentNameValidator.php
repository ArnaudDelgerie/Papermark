<?php

declare(strict_types=1);

namespace App\Validator\Document;

use App\Enum\DocumentExtension;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class DocumentNameValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof DocumentName) {
            throw new UnexpectedTypeException($constraint, DocumentName::class);
        }

        if (!\is_string($value) || $value === '' || $value !== basename($value)) {
            $this->context->buildViolation($constraint->invalidMessage)->addViolation();

            return;
        }

        if (!DocumentExtension::isDocument($value)) {
            $this->context->buildViolation($constraint->extensionMessage)->addViolation();
        }
    }
}
