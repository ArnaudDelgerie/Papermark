<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\File\DirectoryTree;
use App\File\DirectoryTreeResult;
use App\File\OpenDirectory;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

/**
 * Left column, dir mode: mode selector, Open (directory) and the .md tree of
 * the directory open in session. The whole tree is read once here, at render
 * (see EDITOR_FOLDER_MODE.md) — no client-side loading.
 */
#[AsTwigComponent('mode-dir')]
final class ModeDir
{
    public function __construct(
        private readonly OpenDirectory $openDirectory,
        private readonly DirectoryTree $directoryTree,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[ExposeInTemplate(name: 'tree_result')]
    public function getTreeResult(): ?DirectoryTreeResult
    {
        $directory = $this->openDirectory->get();
        if ($directory === null) {
            return null;
        }

        try {
            return $this->directoryTree->build($directory);
        } catch (DirectoryNotFoundException) {
            // The session held a path that no longer exists (moved, unmounted…).
            return null;
        }
    }

    #[ExposeInTemplate(name: 'csrf_token')]
    public function getCsrfToken(): string
    {
        return $this->csrfTokenManager->getToken('dir_open')->getValue();
    }
}
