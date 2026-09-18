<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

/**
 * Left column, single-file mode: mode selector, Open (file) and the history
 * of opened files. History lives in sessionStorage, entirely managed by the
 * mode-single Stimulus controller. Delete/rename (EDITOR_FIX.md #5) are
 * posted by the master (editor-state), which holds the tokens.
 */
#[AsTwigComponent('mode-single')]
final class ModeSingle
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.mode.file.';

    /** Whether this column is the one showing; both are always in the page. */
    public bool $active = false;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
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
