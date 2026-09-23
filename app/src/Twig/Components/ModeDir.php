<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Service\Editor\EditorState;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

/**
 * Left column, dir mode: the current-directory component and the frame that
 * loads the .md tree. The tree itself comes from OpenDirectoryTree, rendered
 * inside that frame, so the column paints without waiting for it.
 */
#[AsTwigComponent('mode-dir')]
final class ModeDir
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.mode.file.';

    /** Whether this column is the one showing; both are always in the page. */
    public bool $active = false;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly EditorState $editorState,
    ) {
    }

    /**
     * The refresh button only makes sense with a folder open.
     */
    #[ExposeInTemplate(name: 'has_directory')]
    public function hasDirectory(): bool
    {
        return $this->editorState->getDir() !== null;
    }

    /**
     * The folder whose tree the column starts with, so the front can tell a
     * failed change of folder (the state's dir differs from the shown one)
     * from the one it already shows (FRT-03, lot 08).
     */
    #[ExposeInTemplate(name: 'dir')]
    public function getDir(): ?string
    {
        return $this->editorState->getDir();
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    #[ExposeInTemplate(name: 'i18n')]
    public function getI18n(): array
    {
        return [
            'rename' => $this->trans('rename'),
            'delete' => $this->trans('delete'),
            // SET-05, lot 09: the rename/delete dialogs' Cancel.
            'cancel' => $this->trans('cancel'),
            'renamePrompt' => $this->trans('rename_prompt'),
            'deleteConfirmMessage' => $this->trans('delete_confirm_message'),
            'deleteConfirmMessageCurrent' => $this->trans('delete_confirm_message_current'),
            'deleteConfirmQuestion' => $this->trans('delete_confirm_question'),
            'deleted' => $this->trans('deleted'),
            'renamed' => $this->trans('renamed'),
            // HUB-06, lot 08: pickers of the file entries.
            'ipc' => [
                'unavailable' => $this->translator->trans('components.ipc.unavailable', [], self::TRANSLATION_DOMAIN),
                'rejected' => $this->translator->trans('components.ipc.rejected', [], self::TRANSLATION_DOMAIN),
            ],
            // FRT-06, lot 08: the front-rendered error zone when the tree
            // frame could not be fetched at all.
            'tree' => [
                'loadFailed' => $this->translator->trans('components.mode.tree.load_failed', [], self::TRANSLATION_DOMAIN),
                'retry' => $this->translator->trans('components.mode.tree.retry', [], self::TRANSLATION_DOMAIN),
            ],
        ];
    }

    private function trans(string $key): string
    {
        return $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN);
    }
}
