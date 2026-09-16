<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\File\DirectoryTree;
use App\File\DirectoryTreeResult;
use App\File\OpenDirectory;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
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
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.mode.file.';

    public function __construct(
        private readonly OpenDirectory $openDirectory,
        private readonly DirectoryTree $directoryTree,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[ExposeInTemplate(name: 'open_directory')]
    public function getOpenDirectory(): ?string
    {
        return $this->openDirectory->get();
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

    /**
     * Delete/rename (EDITOR_FIX.md #5) reuse the 'file' CSRF token already
     * used by FileController's other fetch actions.
     */
    #[ExposeInTemplate(name: 'file_csrf_token')]
    public function getFileCsrfToken(): string
    {
        return $this->csrfTokenManager->getToken('file')->getValue();
    }

    /**
     * @return array<string, string>
     */
    #[ExposeInTemplate(name: 'i18n')]
    public function getI18n(): array
    {
        return [
            'rename' => $this->trans('rename'),
            'delete' => $this->trans('delete'),
            'renamePrompt' => $this->trans('rename_prompt'),
            'deleteConfirmMessage' => $this->trans('delete_confirm_message'),
            'deleteConfirmQuestion' => $this->trans('delete_confirm_question'),
            'deleted' => $this->trans('deleted'),
            'renamed' => $this->trans('renamed'),
            'renameFailed' => $this->trans('rename_failed'),
            'deleteFailed' => $this->trans('delete_failed'),
        ];
    }

    private function trans(string $key): string
    {
        return $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN);
    }
}
