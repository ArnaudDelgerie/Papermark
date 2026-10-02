<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Dto\Document\DocumentContent;
use App\Event\Document\DocumentDeleted;
use App\Event\Document\DocumentRenamed;
use App\Event\Document\DocumentSaved;
use App\Exception\Document\DocumentGoneException;
use App\Exception\Document\DocumentModifiedException;
use App\Exception\Document\DocumentNotUtf8Exception;
use App\Exception\Path\PathNotFoundException;
use App\Service\Path\PathPolicy;
use App\Service\SafeFilesystem;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The four operations a document supports (lot 03-services-document.md):
 * read, save, delete, rename. Extension checking is the caller's job
 * (décision 1 — today the controller, later the request DTO), not this
 * service's: it works on whatever path it is given.
 *
 * Every refusal is one of this app's own exceptions; every successful write
 * dispatches an event so the rest of the app (the cached tree, the current
 * file) can react without this service knowing about either.
 */
final class DocumentStore
{
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'];
    private const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'];
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024; // 10 MiB

    public function __construct(
        private readonly PathPolicy $pathPolicy,
        private readonly DocumentCodec $codec,
        private readonly SafeFilesystem $filesystem,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * `$anchor` resolves a relative `$path`, as PathPolicy::read does.
     *
     * @throws PathNotFoundException  the path can't be resolved or read
     * @throws DocumentNotUtf8Exception
     */
    public function read(string $path, ?string $anchor = null): DocumentContent
    {
        $realPath = $this->pathPolicy->read($path, $anchor);
        if ($realPath === null) {
            throw new PathNotFoundException($path);
        }

        $raw = @file_get_contents($realPath);
        if ($raw === false) {
            throw new PathNotFoundException($path);
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            // The file may have changed hands since it was accepted: refused,
            // never converted — a later read would fail the JSON encoding
            // (FIL-09).
            throw new DocumentNotUtf8Exception($realPath);
        }

        return new DocumentContent($realPath, $this->codec->toEditor($raw), $this->codec->revision($raw));
    }

    /**
     * The image an open document refers to (lot 04-document-controller.md):
     * `$anchor` resolves a relative `$path`, as `read()` does. Kept to
     * `IMAGE_EXTENSIONS`, `IMAGE_MIME_TYPES` and `MAX_IMAGE_SIZE` — a
     * document's own extensions don't apply here.
     *
     * @throws PathNotFoundException the path can't be resolved or isn't a valid image
     */
    public function readImage(string $path, ?string $anchor): string
    {
        $realPath = $this->pathPolicy->read($path, $anchor, new FileConstraint(
            extensions: self::IMAGE_EXTENSIONS,
            mimeTypes: self::IMAGE_MIME_TYPES,
            maxSize: self::MAX_IMAGE_SIZE,
        ));

        if ($realPath === null) {
            throw new PathNotFoundException($path);
        }

        return $realPath;
    }

    /**
     * Writes the document whole, or not at all. `$clientRevision` — what the
     * caller read — is compared against the file as it is now: a change or a
     * disappearance since answers a refusal without touching anything
     * (FIL-03). A content identical to the disk, once put in its shape,
     * answers success without writing: the file keeps its date, no other app
     * sees it move (FIL-11) — but the event still fires, so the tree and the
     * current file stay in step regardless.
     *
     * @throws DocumentGoneException
     * @throws DocumentModifiedException
     */
    public function save(string $path, string $content, ?string $clientRevision): DocumentSaved
    {
        $realPath = $this->pathPolicy->save($path);

        $diskBytes = is_file($realPath) ? @file_get_contents($realPath) : false;

        if ($clientRevision !== null) {
            if ($diskBytes === false) {
                throw new DocumentGoneException($realPath);
            }
            if ($this->codec->revision($diskBytes) !== $clientRevision) {
                throw new DocumentModifiedException($realPath);
            }
        }

        $finalContent = $this->codec->toDisk($content, $diskBytes === false ? null : $diskBytes);

        if ($diskBytes !== false && $finalContent === $diskBytes) {
            $event = new DocumentSaved($realPath, $this->codec->revision($diskBytes));
        } else {
            $this->filesystem->write($realPath, $finalContent);
            $event = new DocumentSaved($realPath, $this->codec->revision($finalContent));
        }

        $this->eventDispatcher->dispatch($event);

        return $event;
    }

    public function delete(string $path): DocumentDeleted
    {
        $realPath = $this->pathPolicy->delete($path);

        $this->filesystem->delete($realPath);

        $event = new DocumentDeleted($realPath);
        $this->eventDispatcher->dispatch($event);

        return $event;
    }

    /** Renames in place: only the last path segment changes (see EDITOR_FIX.md). */
    public function rename(string $path, string $name): DocumentRenamed
    {
        $realPath = $this->pathPolicy->rename($path);
        $newPath = \dirname($realPath) . '/' . $name;

        $this->filesystem->rename($realPath, $newPath);

        $event = new DocumentRenamed($realPath, $newPath);
        $this->eventDispatcher->dispatch($event);

        return $event;
    }
}
