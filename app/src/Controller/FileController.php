<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class FileController extends AbstractController
{
    private const ALLOWED_EXTENSIONS = ['md', 'markdown', 'txt'];

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/file/open', name: 'app_file_open', methods: ['POST'])]
    public function open(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('file', $csrfToken))) {
            return new JsonResponse(['error' => 'invalid_csrf'], Response::HTTP_FORBIDDEN);
        }

        $path = $request->request->get('path');
        if (!\is_string($path) || $path === '') {
            return new JsonResponse(['error' => 'no_path'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isAllowedExtension($path)) {
            return new JsonResponse(['error' => 'unsupported_file_type'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $content = file_get_contents($realPath);
        if ($content === false) {
            return new JsonResponse(['error' => 'read_error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['content' => $content]);
    }

    #[Route('/file/save', name: 'app_file_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('file', $csrfToken))) {
            return new JsonResponse(['error' => 'invalid_csrf'], Response::HTTP_FORBIDDEN);
        }

        $path = $request->request->get('path');
        $content = $request->request->get('content');

        if (!\is_string($path) || $path === '') {
            return new JsonResponse(['error' => 'no_path'], Response::HTTP_BAD_REQUEST);
        }

        if (!\is_string($content)) {
            return new JsonResponse(['error' => 'no_content'], Response::HTTP_BAD_REQUEST);
        }

        $content = $this->sanitizeMarkdown($content);

        if (!$this->isAllowedExtension($path)) {
            return new JsonResponse(['error' => 'unsupported_file_type'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $parentDir = \dirname($path);
        $realParent = realpath($parentDir);
        if ($realParent === false || !is_dir($realParent) || !is_writable($realParent)) {
            return new JsonResponse(['error' => 'not_writable'], Response::HTTP_FORBIDDEN);
        }

        $realPath = $realParent . '/' . basename($path);

        if (is_file($realPath) && !is_writable($realPath)) {
            return new JsonResponse(['error' => 'not_writable'], Response::HTTP_FORBIDDEN);
        }

        $result = file_put_contents($realPath, $content);
        if ($result === false) {
            return new JsonResponse(['error' => 'write_error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['ok' => true]);
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
}
