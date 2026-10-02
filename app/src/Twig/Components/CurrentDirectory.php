<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Service\Editor\EditorState;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

/**
 * Open folder, and the folder currently held in session. Its own component
 * because it acts on that piece of state: it asks for the change, the master
 * (editor-state) posts it, mode-dir only listens (see EDITOR_REACTIVITY.md).
 */
#[AsTwigComponent('current-directory')]
final class CurrentDirectory
{
    private const TRANSLATION_DOMAIN = 'components';

    public function __construct(
        private readonly EditorState $editorState,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[ExposeInTemplate(name: 'open_directory')]
    public function getOpenDirectory(): ?string
    {
        return $this->editorState->getDir();
    }

    /**
     * @return array<string, array<string, string>>
     */
    #[ExposeInTemplate(name: 'i18n')]
    public function getI18n(): array
    {
        return [
            // HUB-06, lot 08: the Open folder picker.
            'ipc' => [
                'unavailable' => $this->translator->trans('components.ipc.unavailable', [], self::TRANSLATION_DOMAIN),
                'rejected' => $this->translator->trans('components.ipc.rejected', [], self::TRANSLATION_DOMAIN),
            ],
        ];
    }
}
