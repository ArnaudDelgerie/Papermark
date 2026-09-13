<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class UploadController extends AbstractController
{
    private const IMAGES_DIR = 'images';
    private const ALLOWED_MIME_PREFIX = 'image/';
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MiB

    public function __construct(
        #[Autowire('%env(APP_UPLOAD_DIR)%')]
        private readonly string $uploadDir,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/upload/image', name: 'app_upload_image', methods: ['POST'])]
    public function uploadImage(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('upload', $csrfToken))) {
            return new JsonResponse(['error' => 'invalid_csrf'], Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(['error' => 'no_file'], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return new JsonResponse(['error' => 'file_too_large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $mimeType = $file->getMimeType();
        if (!\is_string($mimeType) || !str_starts_with($mimeType, self::ALLOWED_MIME_PREFIX)) {
            return new JsonResponse(['error' => 'not_an_image'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $extension = $this->getExtensionForMime($mimeType);
        if ($extension === null) {
            return new JsonResponse(['error' => 'unsupported_image_type'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $hash = sha1_file($file->getRealPath());
        if ($hash === false) {
            return new JsonResponse(['error' => 'read_error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $subdir = substr($hash, 0, 2);
        $targetDir = $this->uploadDir . '/' . self::IMAGES_DIR . '/' . $subdir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
            return new JsonResponse(['error' => 'storage_error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $filename = $hash . '.' . $extension;
        $targetPath = $targetDir . '/' . $filename;

        if (!file_exists($targetPath)) {
            $file->move($targetDir, $filename);
        }

        $url = '/uploads/images/' . $subdir . '/' . $filename;

        return new JsonResponse(['url' => $url], Response::HTTP_CREATED);
    }

    #[Route('/uploads/images/{subdir}/{filename}', name: 'app_serve_image', methods: ['GET'], requirements: ['subdir' => '[0-9a-f]{2}', 'filename' => '[0-9a-f]+\.[a-z]+'])]
    public function serveImage(string $subdir, string $filename): Response
    {
        $path = $this->uploadDir . '/' . self::IMAGES_DIR . '/' . $subdir . '/' . $filename;

        if (!is_file($path)) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
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

    #[Route('/upload/image', name: 'app_delete_image', methods: ['DELETE'])]
    public function deleteImage(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('upload', $csrfToken))) {
            return new JsonResponse(['error' => 'invalid_csrf'], Response::HTTP_FORBIDDEN);
        }

        $url = $request->request->get('url');
        if (!\is_string($url)) {
            return new JsonResponse(['error' => 'no_url'], Response::HTTP_BAD_REQUEST);
        }

        $path = $this->resolvePathFromUrl($url);
        if ($path === null) {
            return new JsonResponse(['error' => 'invalid_url'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_file($path)) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        if (!unlink($path)) {
            return new JsonResponse(['error' => 'delete_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $dir = \dirname($path);
        if (is_dir($dir) && count(scandir($dir)) <= 2) {
            @rmdir($dir);
        }

        return new JsonResponse(['ok' => true]);
    }

    private function resolvePathFromUrl(string $url): ?string
    {
        $prefix = '/uploads/images/';
        if (!str_starts_with($url, $prefix)) {
            return null;
        }

        $relative = substr($url, \strlen($prefix));
        if (!preg_match('/^([0-9a-f]{2})\/([0-9a-f]+\.[a-z]+)$/', $relative, $matches)) {
            return null;
        }

        $path = $this->uploadDir . '/' . self::IMAGES_DIR . '/' . $matches[1] . '/' . $matches[2];
        $realPath = realpath($path);

        if ($realPath === false || !str_starts_with($realPath, realpath($this->uploadDir . '/' . self::IMAGES_DIR))) {
            return null;
        }

        return $realPath;
    }
}
