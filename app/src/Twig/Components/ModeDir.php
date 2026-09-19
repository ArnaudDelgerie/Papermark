<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Editor\EditorState;
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
        ];
    }

    private function trans(string $key): string
    {
        return $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN);
    }
}
