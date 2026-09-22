<?php

declare(strict_types=1);

namespace App\Tests\Service\Archive;

use App\Service\Archive\ArchiveTargetResolver;
use PHPUnit\Framework\TestCase;

final class ArchiveTargetResolverTest extends TestCase
{
    private string $dir;
    private ArchiveTargetResolver $resolver;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/archive_target_test_' . uniqid();
        mkdir($this->dir, 0o777, true);
        $this->resolver = new ArchiveTargetResolver();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testAppendsZipExtensionWhenFree(): void
    {
        $requested = $this->dir . '/archive';

        self::assertSame($requested . '.zip', $this->resolver->resolve($requested));
    }

    public function testSuffixesWhenAppendedNameAlreadyExists(): void
    {
        $requested = $this->dir . '/archive';
        touch($requested . '.zip');

        self::assertSame($this->dir . '/archive (1).zip', $this->resolver->resolve($requested));
    }

    public function testSuffixIncrementsPastMultipleCollisions(): void
    {
        $requested = $this->dir . '/archive';
        touch($requested . '.zip');
        touch($this->dir . '/archive (1).zip');

        self::assertSame($this->dir . '/archive (2).zip', $this->resolver->resolve($requested));
    }

    public function testKeepsPathUnchangedWhenAlreadyZip(): void
    {
        $requested = $this->dir . '/archive.zip';
        touch($requested);

        self::assertSame($requested, $this->resolver->resolve($requested));
    }

    public function testKeepsPathUnchangedWhenAlreadyZipCaseInsensitive(): void
    {
        $requested = $this->dir . '/archive.ZIP';

        self::assertSame($requested, $this->resolver->resolve($requested));
    }
}
