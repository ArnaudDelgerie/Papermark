<?php

namespace App\Controller;

use App\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class UploadController extends AbstractController
{
    private const IMAGES_DIR = 'images';
    // Destination for clipboard/drag-drop images: the browser never gives a
    // real source path for those, so they're written inside a dedicated
    // subfolder of the user's images folder — see EDITOR_IMAGES.md.
    // @todo Verified 2026-09-15: neither path reaches this in the TFSApp hub's
    // webview (WebKitGTK/Tauri never exposes a real File for paste or drop),
    // making uploadImage() below dead code from the UI's point of view.
    // Remove this constant along with uploadImage() if nothing else needs it
    // by project end.
    private const CLIPBOARD_DIR = 'clipboard';
    private const ALLOWED_MIME_PREFIX = 'image/';
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MiB
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.editor.error.';

    public function __construct(
        #[Autowire('%env(APP_UPLOAD_DIR)%')]
        private readonly string $uploadDir,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly SettingRepository $settings,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    // @todo See the @todo on CLIPBOARD_DIR above: unreachable from the editor
    // in the TFSApp hub's webview, kept only in case another caller shows up.
    #[Route('/upload/image', name: 'app_upload_image', methods: ['POST'])]
    public function uploadImage(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('upload', $csrfToken))) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->errorResponse('no_file', Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return $this->errorResponse('file_too_large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $mimeType = $file->getMimeType();
        if (!\is_string($mimeType) || !str_starts_with($mimeType, self::ALLOWED_MIME_PREFIX)) {
            return $this->errorResponse('not_an_image', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $extension = $this->getExtensionForMime($mimeType);
        if ($extension === null) {
            return $this->errorResponse('unsupported_image_type', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $imageFolder = $this->settings->getOrCreate()->getImageFolder();
        if ($imageFolder === null) {
            return $this->errorResponse('storage_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $targetDir = $imageFolder . '/' . self::CLIPBOARD_DIR;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
            return $this->errorResponse('storage_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $filename = (new \DateTimeImmutable())->format('Y-m-d_His-u') . '.' . $extension;
        $file->move($targetDir, $filename);

        $realPath = realpath($targetDir . '/' . $filename);
        if ($realPath === false) {
            return $this->errorResponse('storage_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $url = $this->urlGenerator->generate('app_file_image', ['path' => $realPath]);

        return new JsonResponse(['url' => $url], Response::HTTP_CREATED);
    }

    #[Route('/uploads/images/{subdir}/{filename}', name: 'app_serve_image', methods: ['GET'], requirements: ['subdir' => '[0-9a-f]{2}', 'filename' => '[0-9a-f]+\.[a-z]+'])]
    public function serveImage(string $subdir, string $filename): Response
    {
        $path = $this->uploadDir . '/' . self::IMAGES_DIR . '/' . $subdir . '/' . $filename;

        if (!is_file($path)) {
            return $this->errorResponse('not_found', Response::HTTP_NOT_FOUND);
        }

        $mimeTypes = MimeTypes::getDefault();
        $contentType = $mimeTypes->guessMimeType($path) ?? 'application/octet-stream';

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', 'inline; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');

        return $response;
    }

    private function getExtensionForMime(string $mimeType): ?string
    {
        return match ($mimeType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/avif' => 'avif',
            default => null,
        };
    }

    private function errorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN)],
            $status,
        );
    }
}
