<?php

namespace App\Controller;

use App\Enum\DocumentExtension;
use App\Exception\InvalidRequestException;
use App\Response\StateSuccessResponse;
use App\Service\Document\DocumentCodec;
use App\Service\Document\DocumentStore;
use App\Service\EditorState;
use App\Service\Path\PathPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Validator\Constraints\File as FileConstraint;

final class FileController extends AbstractController
{
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'];
    private const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'];
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024; // 10 MiB

    public function __construct(
        private readonly PathPolicy $pathPolicy,
        private readonly DocumentStore $documentStore,
        private readonly DocumentCodec $documentCodec,
        private readonly EditorState $editorState,
    ) {
    }

    /**
     * Converts /file/image service URLs back to their raw path just before
     * the markdown leaves the editor via copy — the same transform applied to
     * the content written by save(), <br> stripped included, see
     * EDITOR_IMAGES.md.
     */
    #[Route('/file/copy', name: 'app_file_copy', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function copy(Request $request): JsonResponse
    {
        $content = $request->request->get('content');
        if (!\is_string($content)) {
            throw new InvalidRequestException('no_content');
        }

        return new JsonResponse(['content' => $this->documentCodec->toDisk($content, null)]);
    }

    /**
     * Serves an image referenced from an opened document: `path` as written
     * in the markdown (relative or absolute). A relative path resolves
     * against the session's current file — never an anchor the caller picks
     * — and the whole route answers 404 unless the /editor page was rendered
     * for this session: a third-party page holds no session cookie
     * (SameSite=lax), so it can't use this route as an existence oracle
     * (lot 02-chemins.md, SEC-04). No containment under a parent directory:
     * trust comes from having opened the document, not from a per-image
     * gesture — see EDITOR_IMAGES.md.
     */
    #[Route('/file/image', name: 'app_file_image', methods: ['GET'])]
    public function image(Request $request): Response
    {
        if (!$this->editorState->hasOpened()) {
            throw new NotFoundHttpException();
        }

        $path = $request->query->get('path');
        if (!\is_string($path) || $path === '') {
            throw new NotFoundHttpException();
        }

        $realPath = $this->pathPolicy->read($path, $this->editorState->getFile(), new FileConstraint(
            extensions: self::IMAGE_EXTENSIONS,
            mimeTypes: self::IMAGE_MIME_TYPES,
            maxSize: self::MAX_IMAGE_SIZE,
        ));

        if ($realPath === null) {
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
    #[Route('/file/save', name: 'app_file_save', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function save(Request $request): JsonResponse
    {
        $path = $request->request->get('path');
        $content = $request->request->get('content');

        if (!\is_string($path) || $path === '') {
            throw new InvalidRequestException('no_path');
        }

        if (!\is_string($content)) {
            throw new InvalidRequestException('no_content');
        }

        if (!DocumentExtension::isDocument($path)) {
            throw new InvalidRequestException('unsupported_file_type');
        }

        $revision = $request->request->get('revision');
        $clientRevision = \is_string($revision) && $revision !== '' ? $revision : null;

        $event = $this->documentStore->save($path, $content, $clientRevision);

        return new StateSuccessResponse($this->editorState, ['path' => $event->path, 'revision' => $event->revision]);
    }

    /**
     * No image cleanup: images referenced from the markdown may belong to the
     * user and be used elsewhere, they're left untouched (see EDITOR_FIX.md).
     */
    #[Route('/file/delete', name: 'app_file_delete', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function delete(Request $request): JsonResponse
    {
        $path = $request->request->get('path');
        if (!\is_string($path) || $path === '') {
            throw new InvalidRequestException('no_path');
        }

        $event = $this->documentStore->delete($path);

        return new StateSuccessResponse($this->editorState, ['path' => $event->path]);
    }

    /**
     * Renames a file in place: only the last path segment changes, the file
     * stays in the same directory (see EDITOR_FIX.md). The move itself is a
     * no-replace one: a target created meanwhile is refused, not silently
     * overwritten (lot 02-chemins.md, FIL-06).
     */
    #[Route('/file/rename', name: 'app_file_rename', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function rename(Request $request): JsonResponse
    {
        $path = $request->request->get('path');
        $name = $request->request->get('name');

        if (!\is_string($path) || $path === '') {
            throw new InvalidRequestException('no_path');
        }

        if (!\is_string($name) || $name === '' || $name !== basename($name)) {
            throw new InvalidRequestException('invalid_name');
        }

        if (!DocumentExtension::isDocument($name)) {
            throw new InvalidRequestException('unsupported_file_type');
        }

        $event = $this->documentStore->rename($path, $name);

        // Both paths whether or not it is the current file: the sidebar
        // needs them for its own entries.
        return new StateSuccessResponse($this->editorState, ['oldPath' => $event->oldPath, 'newPath' => $event->newPath]);
    }
}
