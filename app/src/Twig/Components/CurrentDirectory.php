<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Editor\EditorState;
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
    public function __construct(
        private readonly EditorState $editorState,
    ) {
    }

    #[ExposeInTemplate(name: 'open_directory')]
    public function getOpenDirectory(): ?string
    {
        return $this->editorState->getDir();
    }
}
