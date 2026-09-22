<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Document\CopyDocumentRequest;
use App\Dto\Document\DeleteDocumentRequest;
use App\Dto\Document\RenameDocumentRequest;
use App\Dto\Document\SaveDocumentRequest;
use App\Exception\Path\PathNotFoundException;
use App\Response\StateSuccessResponse;
use App\Service\Document\DocumentCodec;
use App\Service\Document\DocumentStore;
use App\Service\EditorState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * The content of the current document (lot 04-document-controller.md):
 * read, copy, image, save, delete, rename. Everything here delegates to
 * DocumentStore or DocumentCodec — no disk access in this class.
 */
final class DocumentController extends AbstractController
{
    public function __construct(
        private readonly DocumentStore $documentStore,
        private readonly DocumentCodec $documentCodec,
        private readonly EditorState $editorState,
    ) {
    }

    /**
     * Content of the current file. No parameter: the only way to change what
     * is read is setFile() (POST /editor/file), behind its CSRF token. A file
     * gone from disk is dropped from the state, and its path comes back with
     * the 404 since the caller has no other way to know which one it was.
     */
    #[Route('/document', name: 'app_document_read', methods: ['GET'])]
    public function read(): JsonResponse
    {
        $path = $this->editorState->getFile();
        if ($path === null) {
            return new JsonResponse(['path' => null, 'content' => null]);
        }

        try {
            $document = $this->documentStore->read($path);
        } catch (PathNotFoundException $e) {
            $this->editorState->setFile(null);

            throw $e;
        }

        return new JsonResponse([
            'path' => $document->path,
            'content' => $document->content,
            'revision' => $document->revision,
        ]);
    }

    /**
     * Converts /document/image service URLs back to their raw path just
     * before the markdown leaves the editor via copy — the same transform
     * applied to the content written by save(), <br> stripped included, see
     * EDITOR_IMAGES.md.
     */
    #[Route('/document/copy', name: 'app_document_copy', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function copy(#[MapRequestPayload(mapWhenEmpty: true)] CopyDocumentRequest $payload): JsonResponse
    {
        \assert($payload->content !== null);

        return new JsonResponse(['content' => $this->documentCodec->toDisk($payload->content, null)]);
    }

    /**
     * Serves an image referenced from an opened document: `path` as written
     * in the markdown (relative or absolute). A relative path resolves
     * against the session's current file — never an anchor the caller picks
     * — and the whole route answers 404 unless the /editor page was rendered
     * for this session: a third-party page holds no session cookie
     * (SameSite=lax), so it can't use this route as an existence oracle
     * (lot 02-chemins.md, SEC-04). No DTO: a #[MapQueryString] refusal would
     * answer in JSON, where SEC-04 wants a 404 without a body.
     */
    #[Route('/document/image', name: 'app_document_image', methods: ['GET'])]
    public function image(Request $request): Response
    {
        if (!$this->editorState->hasOpened()) {
            throw new NotFoundHttpException();
        }

        $path = $request->query->get('path');
        if (!\is_string($path) || $path === '') {
            throw new NotFoundHttpException();
        }

        try {
            $realPath = $this->documentStore->readImage($path, $this->editorState->getFile());
        } catch (PathNotFoundException) {
            throw new NotFoundHttpException();
        }

        $contentType = MimeTypes::getDefault()->guessMimeType($realPath) ?? 'application/octet-stream';

        $response = new BinaryFileResponse($realPath);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', 'inline; filename="' . basename($realPath) . '"');

        return $response;
    }

    /**
     * Writes the markdown whole, or not at all (lot 03-services-document.md).
     * A request that carries the revision it read answers 409 when the file
     * changed or disappeared meanwhile, without touching anything. The
     * revision comes back renewed with the path, so the next save compares
     * against what was written — no revision (Save as, Écraser) skips the
     * control entirely.
     */
    #[Route('/document/save', name: 'app_document_save', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function save(#[MapRequestPayload(mapWhenEmpty: true)] SaveDocumentRequest $payload): JsonResponse
    {
        \assert($payload->content !== null);

        $clientRevision = $payload->revision !== null && $payload->revision !== '' ? $payload->revision : null;

        $event = $this->documentStore->save($payload->path, $payload->content, $clientRevision);

        return new StateSuccessResponse($this->editorState, ['path' => $event->path, 'revision' => $event->revision]);
    }

    /**
     * No image cleanup: images referenced from the markdown may belong to the
     * user and be used elsewhere, they're left untouched (see EDITOR_FIX.md).
     */
    #[Route('/document/delete', name: 'app_document_delete', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function delete(#[MapRequestPayload(mapWhenEmpty: true)] DeleteDocumentRequest $payload): JsonResponse
    {
        $event = $this->documentStore->delete($payload->path);

        return new StateSuccessResponse($this->editorState, ['path' => $event->path]);
    }

    /**
     * Renames a file in place: only the last path segment changes, the file
     * stays in the same directory (see EDITOR_FIX.md). The move itself is a
     * no-replace one: a target created meanwhile is refused, not silently
     * overwritten (lot 02-chemins.md, FIL-06).
     */
    #[Route('/document/rename', name: 'app_document_rename', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function rename(#[MapRequestPayload(mapWhenEmpty: true)] RenameDocumentRequest $payload): JsonResponse
    {
        $event = $this->documentStore->rename($payload->path, $payload->name);

        // Both paths whether or not it is the current file: the sidebar
        // needs them for its own entries.
        return new StateSuccessResponse($this->editorState, ['oldPath' => $event->oldPath, 'newPath' => $event->newPath]);
    }
}
