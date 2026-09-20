<?php

declare(strict_types=1);

namespace App\Tests\File;

use App\File\PathPolicy;
use App\File\PathRefusal;
use App\File\PathResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Validation;

final class PathPolicyTest extends TestCase
{
    private PathPolicy $policy;
    private string $root;

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        $this->policy = new PathPolicy(new PathResolver(new Filesystem(), Validation::createValidator()));
        $this->root = sys_get_temp_dir() . '/path_policy_test_' . uniqid();
        mkdir($this->root);
        $this->tempPaths[] = $this->root;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_link($path)) {
                @unlink($path);
            }
        }
        $this->removeDirectory($this->root);
    }

    public function testReadResolvesAnExistingFile(): void
    {
        $path = $this->createFile('doc.md', '# Hello');

        self::assertSame($path, $this->policy->read($path));
    }

    public function testReadFollowsASymlink(): void
    {
        $target = $this->createFile('doc.md', '# Target');
        $link = $this->root . '/link.md';
        symlink($target, $link);

        // Reading a linked .md is a normal use: the target's path comes back.
        self::assertSame($target, $this->policy->read($link));
    }

    public function testReadReturnsNullForAMissingFileOrADirectoryOrAnUnreadableOne(): void
    {
        self::assertNull($this->policy->read('/tmp/this_file_does_not_exist_12345.md'));
        self::assertNull($this->policy->read($this->root));

        $unreadable = $this->createFile('doc.md', '# Unreadable');
        chmod($unreadable, 0o000);
        $this->tempPaths[] = $unreadable;
        if (is_readable($unreadable)) {
            // Running as root defeats permission checks: nothing to assert.
            chmod($unreadable, 0o644);

            self::addToAssertionCount(1);
        } else {
            self::assertNull($this->policy->read($unreadable));
            chmod($unreadable, 0o644);
        }
    }

    public function testReadResolvesARelativePathAgainstTheAnchorAndValidatesTheConstraint(): void
    {
        $image = $this->createFile('photo.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $anchor = $this->createFile('doc.md', '# Doc');

        self::assertSame($image, $this->policy->read('photo.png', $anchor, $this->imageConstraint()));
        // The extension is refused by the constraint, not by the anchor.
        self::assertNull($this->policy->read('doc.md', $anchor, $this->imageConstraint()));
    }

    public function testWriteAllowsAFileThatDoesNotExistYet(): void
    {
        $permission = $this->policy->write($this->root . '/new.md');

        self::assertNotNull($permission->path);
        self::assertSame($this->root . '/new.md', $permission->path);
        self::assertNull($permission->refusal);
    }

    public function testWriteAllowsAnExistingRegularFile(): void
    {
        $path = $this->createFile('doc.md', '# Hello');

        self::assertNotNull($this->policy->write($path)->path);
    }

    public function testWriteRefusesASymlinkWithoutFollowingIt(): void
    {
        $target = $this->createFile('target.md', '# Target');
        $link = $this->root . '/link.md';
        symlink($target, $link);
        $this->tempPaths[] = $link;

        $permission = $this->policy->write($link);

        self::assertNull($permission->path);
        self::assertSame(PathRefusal::Symlink, $permission->refusal);
    }

    public function testWriteRefusesADirectoryAsThePath(): void
    {
        mkdir($this->root . '/a_dir');

        $permission = $this->policy->write($this->root . '/a_dir');

        self::assertNull($permission->path);
        self::assertSame(PathRefusal::NotAFile, $permission->refusal);
    }

    public function testWriteRefusesAPathWhoseParentCantBeResolved(): void
    {
        $permission = $this->policy->write('/no/such/dir_' . uniqid() . '/doc.md');

        self::assertNull($permission->path);
        self::assertSame(PathRefusal::NotFound, $permission->refusal);
    }

    public function testListResolvesAnExistingDirectoryOnly(): void
    {
        $file = $this->createFile('doc.md', '# Hello');

        self::assertSame($this->root, $this->policy->list($this->root));
        self::assertNull($this->policy->list('/tmp/this_dir_does_not_exist_12345'));
        self::assertNull($this->policy->list($file));
    }

    private function imageConstraint(): File
    {
        return new File(extensions: ['png'], mimeTypes: ['image/png']);
    }

    private function createFile(string $name, string $content): string
    {
        $path = $this->root . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $itemPath = $dir . '/' . $item;
            is_dir($itemPath) && !is_link($itemPath) ? $this->removeDirectory($itemPath) : @unlink($itemPath);
        }

        @rmdir($dir);
    }
}
