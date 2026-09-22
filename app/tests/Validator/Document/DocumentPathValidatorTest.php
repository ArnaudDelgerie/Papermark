<?php

declare(strict_types=1);

namespace App\Tests\Validator\Document;

use App\Validator\Document\DocumentPath;
use App\Validator\Document\DocumentPathValidator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<DocumentPathValidator>
 */
final class DocumentPathValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): DocumentPathValidator
    {
        return new DocumentPathValidator();
    }

    public function testAValidDocumentPathRaisesNothing(): void
    {
        $this->validate('/notes/a.md', new DocumentPath());

        $this->assertNoViolation();
    }

    /** Casing doesn't matter for the extension. */
    public function testAnUppercaseExtensionIsAccepted(): void
    {
        $this->validate('/notes/A.MD', new DocumentPath());

        $this->assertNoViolation();
    }

    public function testAnEmptyPathIsBlank(): void
    {
        $constraint = new DocumentPath();
        $this->validate('', $constraint);

        $this->buildViolation($constraint->blankMessage)->assertRaised();
    }

    public function testANonStringValueIsBlank(): void
    {
        $constraint = new DocumentPath();
        $this->validate(null, $constraint);

        $this->buildViolation($constraint->blankMessage)->assertRaised();
    }

    public function testAnUnsupportedExtensionIsRefused(): void
    {
        $constraint = new DocumentPath();
        $this->validate('/notes/a.png', $constraint);

        $this->buildViolation($constraint->extensionMessage)->assertRaised();
    }
}
