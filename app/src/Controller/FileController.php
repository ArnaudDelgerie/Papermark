<?php

namespace App\Controller;

use App\Editor\ModeSession;
use App\File\MarkdownImageUrls;
use App\File\PathResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Contracts\Translation\TranslatorInterface;

final class FileController extends AbstractController
{
    private const ALLOWED_EXTENSIONS = ['md', 'markdown', 'txt'];
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif'];
    private const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml', 'image/avif'];
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024; // 10 MiB
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.editor.error.';

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly PathResolver $pathResolver,
        private readonly MarkdownImageUrls $markdownImageUrls,
        private readonly ModeSession $modeSession,
    ) {
    }

    #[Route('/file/open', name: 'app_file_open', methods: ['POST'])]
    public function open(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('file', $csrfToken))) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $path = $request->request->get('path');
        if (!\is_string($path) || $path === '') {
            return $this->errorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isAllowedExtension($path)) {
            return $this->errorResponse('unsupported_file_type', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $mode = $this->modeSession->getCurrentMode();

        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            // Only drop the mode's remembered file if this failed open *is*
            // that file — a manual open of an unrelated bad path must not
            // wipe out an already-valid association (see EDITOR_FIX.md).
            if ($mode !== null && $this->modeSession->getFile($mode) === $path) {
                $this->modeSession->setFile($mode, null);
            }

            return $this->errorResponse('not_found', Response::HTTP_NOT_FOUND);
        }

        $content = file_get_contents($realPath);
        if ($content === false) {
            return $this->errorResponse('read_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($mode !== null) {
            $this->modeSession->setFile($mode, $realPath);
        }

        return new JsonResponse(['content' => $this->markdownImageUrls->toServiceUrls($content, $realPath)]);
    }

    /**
     * Converts /file/image service URLs back to their raw path just before
     * the markdown leaves the editor via copy — the same conversion applied
     * to the content written by save(), see EDITOR_IMAGES.md.
     */
    #[Route('/file/copy', name: 'app_file_copy', methods: ['POST'])]
    public function copy(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('file', $csrfToken))) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $content = $request->request->get('content');
        if (!\is_string($content)) {
            return $this->errorResponse('no_content', Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['content' => $this->markdownImageUrls->toRawPaths($content)]);
    }

    /**
     * Serves an image referenced from an opened document: `path` as written
     * in the markdown (relative or absolute), `anchor` the document's own
     * path (used to resolve a relative `path`). No containment under a
     * parent directory: trust comes from having opened the document, not
     * from a per-image gesture — see EDITOR_IMAGES.md.
     */
    #[Route('/file/image', name: 'app_file_image', methods: ['GET'])]
    public function image(Request $request): Response
    {
        $path = $request->query->get('path');
        if (!\is_string($path) || $path === '') {
            throw new NotFoundHttpException();
        }

        $anchor = $request->query->get('anchor');

        $realPath = $this->pathResolver->resolve($path, \is_string($anchor) ? $anchor : null, new FileConstraint(
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

    #[Route('/file/save', name: 'app_file_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('file', $csrfToken))) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $path = $request->request->get('path');
        $content = $request->request->get('content');

        if (!\is_string($path) || $path === '') {
            return $this->errorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        if (!\is_string($content)) {
            return $this->errorResponse('no_content', Response::HTTP_BAD_REQUEST);
        }

        $content = $this->sanitizeMarkdown($content);
        $content = $this->markdownImageUrls->toRawPaths($content);

        if (!$this->isAllowedExtension($path)) {
            return $this->errorResponse('unsupported_file_type', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $parentDir = \dirname($path);
        $realParent = realpath($parentDir);
        if ($realParent === false || !is_dir($realParent) || !is_writable($realParent)) {
            return $this->errorResponse('not_writable', Response::HTTP_FORBIDDEN);
        }

        $realPath = $realParent . '/' . basename($path);

        if (is_file($realPath) && !is_writable($realPath)) {
            return $this->errorResponse('not_writable', Response::HTTP_FORBIDDEN);
        }

        $result = file_put_contents($realPath, $content);
        if ($result === false) {
            return $this->errorResponse('write_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * No image cleanup: images referenced from the markdown may belong to the
     * user and be used elsewhere, they're left untouched (see EDITOR_FIX.md).
     */
    #[Route('/file/delete', name: 'app_file_delete', methods: ['POST'])]
    public function delete(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('file', $csrfToken))) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $path = $request->request->get('path');
        if (!\is_string($path) || $path === '') {
            return $this->errorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath)) {
            return $this->errorResponse('not_found', Response::HTTP_NOT_FOUND);
        }

        if (!@unlink($realPath)) {
            return $this->errorResponse('delete_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $mode = $this->modeSession->getCurrentMode();
        if ($mode !== null && $this->modeSession->getFile($mode) === $realPath) {
            $this->modeSession->setFile($mode, null);
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Renames a file in place: only the last path segment changes, the file
     * stays in the same directory (see EDITOR_FIX.md).
     */
    #[Route('/file/rename', name: 'app_file_rename', methods: ['POST'])]
    public function rename(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('file', $csrfToken))) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $path = $request->request->get('path');
        $name = $request->request->get('name');

        if (!\is_string($path) || $path === '') {
            return $this->errorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        if (!\is_string($name) || $name === '' || $name !== basename($name)) {
            return $this->errorResponse('invalid_name', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isAllowedExtension($name)) {
            return $this->errorResponse('unsupported_file_type', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath)) {
            return $this->errorResponse('not_found', Response::HTTP_NOT_FOUND);
        }

        $newPath = \dirname($realPath) . '/' . $name;
        if (file_exists($newPath)) {
            return $this->errorResponse('rename_target_exists', Response::HTTP_CONFLICT);
        }

        if (!@rename($realPath, $newPath)) {
            return $this->errorResponse('write_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $mode = $this->modeSession->getCurrentMode();
        if ($mode !== null && $this->modeSession->getFile($mode) === $realPath) {
            $this->modeSession->setFile($mode, $newPath);
        }

        return new JsonResponse(['path' => $newPath]);
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

    private function errorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN)],
            $status,
        );
    }
}
