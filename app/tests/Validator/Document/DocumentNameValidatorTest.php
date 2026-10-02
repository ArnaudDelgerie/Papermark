<?php

declare(strict_types=1);

namespace App\Tests\Validator\Document;

use App\Validator\Document\DocumentName;
use App\Validator\Document\DocumentNameValidator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<DocumentNameValidator>
 */
final class DocumentNameValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): DocumentNameValidator
    {
        return new DocumentNameValidator();
    }

    public function testAValidDocumentNameRaisesNothing(): void
    {
        $this->validate('renamed.md', new DocumentName());

        $this->assertNoViolation();
    }

    public function testAnEmptyNameIsInvalid(): void
    {
        $constraint = new DocumentName();
        $this->validate('', $constraint);

        $this->buildViolation($constraint->invalidMessage)->assertRaised();
    }

    public function testANameContainingAPathSeparatorIsInvalid(): void
    {
        $constraint = new DocumentName();
        $this->validate('../evil.md', $constraint);

        $this->buildViolation($constraint->invalidMessage)->assertRaised();
    }

    public function testAnUnsupportedExtensionIsRefused(): void
    {
        $constraint = new DocumentName();
        $this->validate('renamed.exe', $constraint);

        $this->buildViolation($constraint->extensionMessage)->assertRaised();
    }
}
