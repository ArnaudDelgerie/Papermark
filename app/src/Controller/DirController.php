<?php

declare(strict_types=1);

namespace App\Controller;

use App\File\OpenDirectory;
use App\Twig\Components\ModeDir;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DirController extends AbstractController
{
    public function __construct(
        private readonly OpenDirectory $openDirectory,
    ) {
    }

    /**
     * Refreshes the sidebar tree after an in-app change (Save as into the
     * open directory) — not a filesystem watcher, see EDITOR_FOLDER_MODE.md.
     */
    #[Route('/dir/tree', name: 'app_dir_tree', methods: ['GET'])]
    public function tree(ModeDir $modeDir): Response
    {
        return $this->render('components/_mode_dir_tree.html.twig', [
            'tree_result' => $modeDir->getTreeResult(),
        ]);
    }

    /**
     * Stores the picked directory in session then redirects to the dir-mode
     * page, same level of control as /file/open: no filter on location. An
     * invalid CSRF token or path is a silent no-op (see EDITOR_FOLDER_MODE.md).
     */
    #[Route('/dir/open', name: 'app_dir_open', methods: ['POST'])]
    public function open(Request $request): RedirectResponse
    {
        if ($this->isCsrfTokenValid('dir_open', $request->request->get('_token'))) {
            $path = $request->request->get('path');
            $realPath = \is_string($path) ? realpath($path) : false;
            if ($realPath !== false && is_dir($realPath)) {
                $this->openDirectory->set($realPath);
            }
        }

        return $this->redirectToRoute('app_editor_dir');
    }
}
