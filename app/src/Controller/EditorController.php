<?php

declare(strict_types=1);

namespace App\Controller;

use App\Editor\EditorMode;
use App\Editor\ModeSession;
use App\File\MarkdownFileReader;
use App\File\OpenDirectory;
use App\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditorController extends AbstractController
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.editor.error.';

    public function __construct(
        private readonly ModeSession $modeSession,
        private readonly MarkdownFileReader $fileReader,
        private readonly SettingRepository $settings,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The one page. Both left columns are always rendered, so the mode only
     * decides which one shows and which file the editor opens — and it is read
     * from the session, never from the URL, otherwise a reload would undo a
     * switch made without navigating (see EDITOR_REACTIVITY.md).
     */
    #[Route('/editor', name: 'app_editor')]
    public function index(OpenDirectory $openDirectory): Response
    {
        $mode = $this->modeSession->getCurrentMode() ?? $this->settings->getOrCreate()->getDefaultMode();

        return $this->render('editor/page.html.twig', [
            'mode' => $mode->value,
            'directory' => $mode === EditorMode::Dir ? $openDirectory->get() : null,
            ...$this->initialFileVars($mode),
        ]);
    }

    /**
     * Entry points, kept so the switch links mean something outside the app
     * (middle-click, context menu): they only record the mode.
     */
    #[Route('/editor/single', name: 'app_editor_single')]
    public function single(): RedirectResponse
    {
        $this->modeSession->setCurrentMode(EditorMode::Single);

        return $this->redirectToRoute('app_editor');
    }

    #[Route('/editor/dir', name: 'app_editor_dir')]
    public function dir(): RedirectResponse
    {
        $this->modeSession->setCurrentMode(EditorMode::Dir);

        return $this->redirectToRoute('app_editor');
    }

    /**
     * Records the mode in session and hands back the file that mode remembers.
     * Both left columns are already in the page, so nothing is re-rendered: the
     * switch only has the editor's own content to replace (EDITOR_REACTIVITY.md).
     */
    #[Route('/editor/mode', name: 'app_editor_set_mode', methods: ['POST'])]
    public function setMode(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('mode', $csrfToken))) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $value = $request->request->get('mode');
        $mode = \is_string($value) ? EditorMode::tryFrom($value) : null;
        if ($mode === null) {
            return $this->errorResponse('invalid_mode', Response::HTTP_BAD_REQUEST);
        }

        $this->modeSession->setCurrentMode($mode);
        ['initial_path' => $path, 'initial_content' => $content] = $this->initialFileVars($mode);

        return new JsonResponse(['mode' => $mode->value, 'path' => $path, 'content' => $content]);
    }

    private function errorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN)],
            $status,
        );
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
