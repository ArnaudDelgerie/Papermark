<?php

namespace App\Controller;

use App\Editor\EditorState;
use App\File\AtomicFileWriter;
use App\File\DiskFormat;
use App\File\MarkdownImageUrls;
use App\File\NoReplaceRename;
use App\File\OpenDirectoryTree;
use App\File\PathPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Contracts\Translation\TranslatorInterface;

final class FileController extends AbstractController
{
    private const ALLOWED_EXTENSIONS = ['md', 'markdown', 'txt'];
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'];
    private const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'];
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024; // 10 MiB
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.editor.error.';

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly PathPolicy $pathPolicy,
        private readonly NoReplaceRename $noReplaceRename,
        private readonly MarkdownImageUrls $markdownImageUrls,
        private readonly AtomicFileWriter $atomicWriter,
        private readonly DiskFormat $diskFormat,
        private readonly EditorState $editorState,
        private readonly OpenDirectoryTree $openDirectoryTree,
    ) {
    }

    /**
     * Converts /file/image service URLs back to their raw path just before
     * the markdown leaves the editor via copy — the same conversion applied
     * to the content written by save(), see EDITOR_IMAGES.md.
     */
    #[Route('/file/copy', name: 'app_file_copy', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function copy(Request $request): JsonResponse
    {
        $content = $request->request->get('content');
        if (!\is_string($content)) {
            return $this->errorResponse('no_content', Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['content' => $this->markdownImageUrls->toRawPaths($content)]);
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
     * Writes the markdown whole, or not at all (lot 03-enregistrement.md).
     * A request that carries the revision it read answers 409 when the file
     * changed or disappeared meanwhile, without touching anything. The
     * revision comes back renewed with the path, so the next save compares
     * against what was written — no revision (Save as, Écraser) skips the
     * control entirely.
     *
     * A content identical to the disk after BOM and line endings are put
     * back in the file's shape answers success without writing: the file
     * keeps its date, no other app sees it move (FIL-11).
     */
    #[Route('/file/save', name: 'app_file_save', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function save(Request $request): JsonResponse
    {
        $path = $request->request->get('path');
        $content = $request->request->get('content');

        if (!\is_string($path) || $path === '') {
            return $this->stateErrorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        if (!\is_string($content)) {
            return $this->stateErrorResponse('no_content', Response::HTTP_BAD_REQUEST);
        }

        $content = $this->sanitizeMarkdown($content);
        $content = $this->markdownImageUrls->toRawPaths($content);

        if (!$this->isAllowedExtension($path)) {
            return $this->stateErrorResponse('unsupported_file_type', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $realPath = $this->pathPolicy->save($path);

        // rename() asks the folder, not the file: without this control the
        // atomic write would make a read-only file replaceable.
        if (!is_writable(\dirname($realPath)) || (is_file($realPath) && !is_writable($realPath))) {
            return $this->stateErrorResponse('not_writable', Response::HTTP_FORBIDDEN);
        }

        // What the client says it read — the HTTP ETag/If-Match motive.
        $revision = $request->request->get('revision');
        $clientRevision = \is_string($revision) && $revision !== '' ? $revision : null;

        // The reread that verifies the revision is also where the file's
        // byte-level shape (BOM, line endings) is picked up.
        $diskBytes = is_file($realPath) ? @file_get_contents($realPath) : false;

        if ($clientRevision !== null) {
            if ($diskBytes === false) {
                return $this->stateErrorResponse('save_conflict_gone', Response::HTTP_CONFLICT);
            }
            if (hash('xxh128', $diskBytes) !== $clientRevision) {
                return $this->stateErrorResponse('save_conflict_modified', Response::HTTP_CONFLICT);
            }
        }

        if ($diskBytes !== false) {
            $content = $this->diskFormat->apply($content, $diskBytes);

            if ($content === $diskBytes) {
                // Same path on a plain save; on a Save as, the new file
                // becomes the current one, so a reload reopens it.
                $this->openDirectoryTree->fileAdded($realPath);
                $this->editorState->setFile($realPath);

                return $this->stateResponse(['path' => $realPath, 'revision' => hash('xxh128', $diskBytes)]);
            }
        }

        try {
            $this->atomicWriter->write($realPath, $content);
        } catch (IOException) {
            return $this->stateErrorResponse('write_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $this->openDirectoryTree->fileAdded($realPath);

        // Same path on a plain save; on a Save as, the new file becomes the
        // current one, so a reload reopens it (see EDITOR_REACTIVITY.md).
        $this->editorState->setFile($realPath);

        return $this->stateResponse(['path' => $realPath, 'revision' => hash('xxh128', $content)]);
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
            return $this->stateErrorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        $realPath = $this->pathPolicy->delete($path);

        if (!@unlink($realPath)) {
            return $this->stateErrorResponse('delete_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $this->openDirectoryTree->fileRemoved($realPath);

        if ($this->editorState->getFile() === $realPath) {
            $this->editorState->setFile(null);
        }

        return $this->stateResponse(['path' => $realPath]);
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
            return $this->stateErrorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        if (!\is_string($name) || $name === '' || $name !== basename($name)) {
            return $this->stateErrorResponse('invalid_name', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isAllowedExtension($name)) {
            return $this->stateErrorResponse('unsupported_file_type', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $realPath = $this->pathPolicy->rename($path);

        $newPath = \dirname($realPath) . '/' . $name;

        $this->noReplaceRename->rename($realPath, $newPath);
        $this->openDirectoryTree->fileRenamed($realPath, $newPath);

        if ($this->editorState->getFile() === $realPath) {
            $this->editorState->setFile($newPath);
        }

        // Both paths whether or not it is the current file: the sidebar
        // needs them for its own entries.
        return $this->stateResponse(['oldPath' => $realPath, 'newPath' => $newPath]);
    }

    private function isAllowedExtension(string $path): bool
    {
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return \in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    private function sanitizeMarkdown(string $content): string
    {
        return preg_replace('/<br\s*\/?>\n?/i', '', $content);
    }

    /**
     * Same answer as the EditorController routes: the state, and what was
     * done, paths realpath'd (see EDITOR_REACTIVITY.md, S5).
     *
     * @param array<string, string> $action
     */
    private function stateResponse(array $action): JsonResponse
    {
        return new JsonResponse(['state' => $this->editorState->toArray(), 'action' => $action]);
    }

    /**
     * A refusal from a route that writes the state still carries it (S6).
     */
    private function stateErrorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN),
            'state' => $this->editorState->toArray(),
        ], $status);
    }

    private function errorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN)],
            $status,
        );
    }
}
