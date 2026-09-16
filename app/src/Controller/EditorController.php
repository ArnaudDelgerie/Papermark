<?php

declare(strict_types=1);

namespace App\Controller;

use App\Editor\EditorMode;
use App\Editor\ModeSession;
use App\File\MarkdownFileReader;
use App\File\OpenDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EditorController extends AbstractController
{
    public function __construct(
        private readonly ModeSession $modeSession,
        private readonly MarkdownFileReader $fileReader,
    ) {
    }

    #[Route('/editor/single', name: 'app_editor_single')]
    public function single(): Response
    {
        $this->modeSession->setCurrentMode(EditorMode::Single);

        return $this->render('editor/single.html.twig', $this->initialFileVars(EditorMode::Single));
    }

    #[Route('/editor/dir', name: 'app_editor_dir')]
    public function dir(OpenDirectory $openDirectory): Response
    {
        $this->modeSession->setCurrentMode(EditorMode::Dir);

        return $this->render('editor/dir.html.twig', [
            'directory' => $openDirectory->get(),
            ...$this->initialFileVars(EditorMode::Dir),
        ]);
    }

    /**
     * @return array{initial_path: ?string, initial_content: ?string}
     */
    private function initialFileVars(EditorMode $mode): array
    {
        $path = $this->modeSession->getFile($mode);
        if ($path === null) {
            return ['initial_path' => null, 'initial_content' => null];
        }

        $content = $this->fileReader->read($path);
        if ($content === null) {
            // Stale session reference (deleted, moved…): drop it silently,
            // no fallback to another file (see EDITOR_FIX.md).
            $this->modeSession->setFile($mode, null);

            return ['initial_path' => null, 'initial_content' => null];
        }

        return ['initial_path' => $path, 'initial_content' => $content];
    }
}
