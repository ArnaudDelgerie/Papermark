<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Event\Document\DocumentDeleted;
use App\Event\Document\DocumentRenamed;
use App\Event\Document\DocumentSaved;
use App\Exception\Document\DocumentGoneException;
use App\Exception\Document\DocumentModifiedException;
use App\Exception\Document\DocumentNotUtf8Exception;
use App\Exception\Filesystem\RenameTargetExistsException;
use App\Exception\Path\PathNotFoundException;
use App\Exception\Path\PathNotWritableException;
use App\Service\Document\DocumentCodec;
use App\Service\Document\DocumentStore;
use App\Service\Path\PathPolicy;
use App\Service\Path\PathResolver;
use App\Service\SafeFilesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validation;

/**
 * DocumentStore's four operations (lot 03-services-document.md): read, save,
 * delete, rename — each dispatching a Document* event on success.
 */
final class DocumentStoreTest extends TestCase
{
    private string $root;
    private DocumentStore $store;
    private EventDispatcher $eventDispatcher;

    /** @var object[] */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/document_store_test_' . uniqid();
        mkdir($this->root);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name, array $params): string => '/document/image?path=' . $params['path'],
        );

        $this->eventDispatcher = new EventDispatcher();
        $this->dispatchedEvents = [];
        foreach ([DocumentSaved::class, DocumentDeleted::class, DocumentRenamed::class] as $eventClass) {
            $this->eventDispatcher->addListener($eventClass, function (object $event): void {
                $this->dispatchedEvents[] = $event;
            });
        }

        $this->store = new DocumentStore(
            new PathPolicy(new PathResolver(new Filesystem(), Validation::createValidator())),
            new DocumentCodec($urlGenerator),
            new SafeFilesystem(),
            $this->eventDispatcher,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReadReturnsTheConvertedContentAndRevision(): void
    {
        $path = $this->createFile('doc.md', '![alt](./photo.png)');

        $document = $this->store->read($path);

        self::assertSame($path, $document->path);
        self::assertSame('![alt](/document/image?path=./photo.png)', $document->content);
        self::assertSame(hash('xxh128', '![alt](./photo.png)'), $document->revision);
    }

    public function testReadResolvesARelativePathAgainstTheAnchor(): void
    {
        $anchor = $this->createFile('doc.md', '# Doc');
        $target = $this->createFile('sibling.md', '# Sibling');

        $document = $this->store->read('sibling.md', $anchor);

        self::assertSame($target, $document->path);
    }

    public function testReadThrowsForAMissingFile(): void
    {
        $this->expectException(PathNotFoundException::class);

        $this->store->read($this->root . '/missing.md');
    }

    public function testReadThrowsForNonUtf8Content(): void
    {
        $path = $this->createFile('bad.md', "Coucou \xE9\xE8");

        $this->expectException(DocumentNotUtf8Exception::class);

        $this->store->read($path);
    }

    public function testSaveWritesANewFileAndDispatchesTheEvent(): void
    {
        $path = $this->root . '/new.md';

        $event = $this->store->save($path, '# New', null);

        self::assertSame('# New', file_get_contents($path));
        self::assertSame($path, $event->path);
        self::assertSame(hash('xxh128', '# New'), $event->revision);
        self::assertEquals([$event], $this->dispatchedEvents);
    }

    public function testSaveAcceptsAMatchingRevisionAndWrites(): void
    {
        $path = $this->createFile('doc.md', '# On disk');

        $event = $this->store->save($path, '# Edited', hash('xxh128', '# On disk'));

        self::assertSame('# Edited', file_get_contents($path));
        self::assertSame(hash('xxh128', '# Edited'), $event->revision);
    }

    public function testSaveRefusesAStaleRevisionAndLeavesTheFileUntouched(): void
    {
        $path = $this->createFile('doc.md', '# On disk');
        file_put_contents($path, '# Changed outside');

        try {
            $this->store->save($path, '# Mine', hash('xxh128', '# On disk'));
            self::fail('A stale revision must be refused.');
        } catch (DocumentModifiedException) {
        }

        self::assertSame('# Changed outside', file_get_contents($path));
        self::assertSame([], $this->dispatchedEvents);
    }

    public function testSaveRefusesTheRevisionOfAGoneFile(): void
    {
        $path = $this->root . '/gone.md';

        $this->expectException(DocumentGoneException::class);

        $this->store->save($path, '# Mine', hash('xxh128', '# On disk'));
    }

    /** FIL-11: identical content skips the write, but the event still fires. */
    public function testSaveSkipsTheWriteWhenTheContentIsIdenticalButStillDispatches(): void
    {
        $path = $this->createFile('doc.md', "# Same\n");
        touch($path, 1000000000);

        $event = $this->store->save($path, "# Same\n", hash('xxh128', "# Same\n"));

        clearstatcache();
        self::assertSame(1000000000, filemtime($path));
        self::assertEquals([$event], $this->dispatchedEvents);
    }

    public function testSaveRefusesAReadOnlyFile(): void
    {
        $path = $this->createFile('doc.md', '# Read only');
        chmod($path, 0o444);

        try {
            $this->expectException(PathNotWritableException::class);
            $this->store->save($path, '# No', null);
        } finally {
            chmod($path, 0o644);
        }
    }

    public function testDeleteRemovesTheFileAndDispatchesTheEvent(): void
    {
        $path = $this->createFile('doc.md', '# Hello');

        $event = $this->store->delete($path);

        self::assertFileDoesNotExist($path);
        self::assertSame($path, $event->path);
        self::assertEquals([$event], $this->dispatchedEvents);
    }

    public function testDeleteThrowsForAMissingFile(): void
    {
        $this->expectException(PathNotFoundException::class);

        $this->store->delete($this->root . '/missing.md');
    }

    public function testRenameMovesTheFileAndDispatchesTheEvent(): void
    {
        $path = $this->createFile('doc.md', '# Hello');
        $expected = $this->root . '/renamed.md';

        $event = $this->store->rename($path, 'renamed.md');

        self::assertFileDoesNotExist($path);
        self::assertSame('# Hello', file_get_contents($expected));
        self::assertSame($path, $event->oldPath);
        self::assertSame($expected, $event->newPath);
        self::assertEquals([$event], $this->dispatchedEvents);
    }

    public function testRenameRefusesAnExistingTarget(): void
    {
        $path = $this->createFile('a.md', '# A');
        $this->createFile('b.md', '# B');

        $this->expectException(RenameTargetExistsException::class);

        $this->store->rename($path, 'b.md');
    }

    private function createFile(string $name, string $content): string
    {
        $path = $this->root . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            @unlink($dir . '/' . $item);
        }

        @rmdir($dir);
    }
}
