<?php

declare(strict_types=1);

namespace App\Controller;

use App\File\OpenDirectory;
use App\File\OpenDirectoryTree;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DirController extends AbstractController
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.editor.error.';

    public function __construct(
        private readonly OpenDirectory $openDirectory,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Content of the tree frame: loaded asynchronously so the column paints
     * before DirectoryTree::build has walked the folder, and reloaded after
     * an in-app change (Save as, delete, rename, change of current folder) —
     * not a filesystem watcher, see EDITOR_FOLDER_MODE.md.
     */
    #[Route('/dir/tree', name: 'app_dir_tree', methods: ['GET'])]
    public function tree(OpenDirectoryTree $openDirectoryTree): Response
    {
        return $this->render('dir/tree.html.twig', [
            'tree_result' => $openDirectoryTree->build(),
        ]);
    }

    /**
     * Sets the current folder in session. Nothing is "opened" here: the caller
     * reloads the tree frame once the session holds the new path. Same level of
     * control as /file/open, no filter on location (see EDITOR_FOLDER_MODE.md).
     */
    #[Route('/dir/current', name: 'app_dir_set_current', methods: ['POST'])]
    public function setCurrent(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('dir', $csrfToken))) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $path = $request->request->get('path');
        $realPath = \is_string($path) ? realpath($path) : false;
        if ($realPath === false || !is_dir($realPath)) {
            return $this->errorResponse('invalid_directory', Response::HTTP_NOT_FOUND);
        }

        $this->openDirectory->set($realPath);

        return new JsonResponse(['open_directory' => $realPath]);
    }

    private function errorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN)],
            $status,
        );
    }
}
