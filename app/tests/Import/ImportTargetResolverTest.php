<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\ImportTargetResolver;
use PHPUnit\Framework\TestCase;

final class ImportTargetResolverTest extends TestCase
{
    private string $dir;
    private ImportTargetResolver $resolver;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/import_target_test_' . uniqid();
        mkdir($this->dir, 0o777, true);
        $this->resolver = new ImportTargetResolver();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }
        @rmdir($this->dir);
    }

    public function testStripsZipExtensionWhenFree(): void
    {
        self::assertSame($this->dir . '/archive', $this->resolver->resolve($this->dir, 'archive.zip'));
    }

    public function testStripsZipExtensionCaseInsensitively(): void
    {
        self::assertSame($this->dir . '/archive', $this->resolver->resolve($this->dir, 'archive.ZIP'));
    }

    public function testSuffixesWhenNameAlreadyTaken(): void
    {
        mkdir($this->dir . '/archive');

        self::assertSame($this->dir . '/archive (1)', $this->resolver->resolve($this->dir, 'archive.zip'));
    }

    public function testSuffixIncrementsPastMultipleCollisions(): void
    {
        mkdir($this->dir . '/archive');
        mkdir($this->dir . '/archive (1)');

        self::assertSame($this->dir . '/archive (2)', $this->resolver->resolve($this->dir, 'archive.zip'));
    }
}
