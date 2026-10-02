<?php

declare(strict_types=1);

namespace App\Tests\Service\Path;

use App\Service\Path\PathResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Validation;

final class PathResolverTest extends TestCase
{
    private PathResolver $resolver;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->resolver = new PathResolver(new Filesystem(), Validation::createValidator());
    }

    public function testResolvesAbsolutePathWithoutAnchor(): void
    {
        $path = $this->createFile('.png');

        self::assertSame($path, $this->resolver->resolve($path, null, $this->imageConstraint()));
    }

    public function testResolvesRelativePathAgainstAnchorDirectory(): void
    {
        $path = $this->createFile('.png');
        $anchor = \dirname($path) . '/document.md';

        self::assertSame($path, $this->resolver->resolve(basename($path), $anchor, $this->imageConstraint()));
    }

    public function testReturnsNullForRelativePathWithoutAnchor(): void
    {
        self::assertNull($this->resolver->resolve('image.png', null, $this->imageConstraint()));
        self::assertNull($this->resolver->resolve('image.png', '', $this->imageConstraint()));
    }

    public function testReturnsNullForMissingFile(): void
    {
        self::assertNull($this->resolver->resolve('/tmp/does_not_exist_12345.png', null, $this->imageConstraint()));
    }

    public function testReturnsNullWhenConstraintRejectsExtension(): void
    {
        $path = $this->createFile('.txt', 'not an image');

        self::assertNull($this->resolver->resolve($path, null, $this->imageConstraint()));
    }

    private function imageConstraint(): File
    {
        return new File(extensions: ['png', 'jpg', 'jpeg'], mimeTypes: ['image/png', 'image/jpeg']);
    }

    private function createFile(string $suffix, ?string $content = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test_') . $suffix;
        file_put_contents($path, $content ?? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));

        $this->tempFiles[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }
}
