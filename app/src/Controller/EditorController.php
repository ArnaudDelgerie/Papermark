<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiAvailability;
use App\Editor\EditorMode;
use App\Editor\EditorState;
use App\File\MarkdownFileReader;
use App\File\OpenDirectoryTree;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everything that reads or writes the EditorState lives under /editor. The
 * routes that write it return {state, action} whether or not they changed
 * anything, so the caller never has to guess, and {error, state} when they
 * refuse: the state may have been corrected meanwhile (see EDITOR_REACTIVITY.md,
 * S5-S6). `action` is what was done, paths realpath'd.
 */
final class EditorController extends AbstractController
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.editor.error.';

    public function __construct(
        private readonly EditorState $editorState,
        private readonly MarkdownFileReader $fileReader,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The one page. Both left columns are always rendered, so the mode only
     * decides which one shows — and it is read from the session, never from
     * the URL, otherwise a reload would undo a switch made without navigating.
     * The editor gets no file here: it fetches it itself through getFile().
     *
     * `state` hydrates the client store (the editor-state controller). It adds
     * what the routes don't send yet: `readonly`, always off at load, and
     * `ai_enabled` (see EDITOR_TS_MIGRATION.md).
     */
    #[Route('/editor', name: 'app_editor', methods: ['GET'])]
    public function index(AiAvailability $aiAvailability): Response
    {
        $state = $this->editorState->toArray();

        return $this->render('editor/page.html.twig', [
            'mode' => $state['mode'],
            'state' => [...$state, 'readonly' => false, 'ai_enabled' => $aiAvailability->isEnabled()],
        ]);
    }

    /**
     * The state as the session holds it, re-read by the client store after a
     * read found it pointing at something gone (the anomaly, S5).
     */
    #[Route('/editor/state', name: 'app_editor_get_state', methods: ['GET'])]
    public function getState(): JsonResponse
    {
        return new JsonResponse(['state' => $this->editorState->toArray()]);
    }

    /**
     * Records the mode, which drops the current file.
     */
    #[Route('/editor/mode', name: 'app_editor_set_mode', methods: ['POST'])]
    public function setMode(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValidFromHeader($request, 'mode')) {
            return $this->stateErrorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $value = $request->request->get('mode');
        $mode = \is_string($value) ? EditorMode::tryFrom($value) : null;
        if ($mode === null) {
            return $this->stateErrorResponse('invalid_mode', Response::HTTP_BAD_REQUEST);
        }

        $this->editorState->setMode($mode);

        return $this->stateResponse(['mode' => $mode->value]);
    }

    /**
     * Content of the current file. No parameter: the only way to change what
     * is read is setFile(), behind its CSRF token. A file gone from disk is
     * dropped from the state, and its path comes back with the 404 since the
     * caller has no other way to know which one it was.
     */
    #[Route('/editor/file', name: 'app_editor_get_file', methods: ['GET'])]
    public function getFile(): JsonResponse
    {
        $path = $this->editorState->getFile();
        if ($path === null) {
            return new JsonResponse(['path' => null, 'content' => null]);
        }

        $content = $this->fileReader->read($path);
        if ($content === null) {
            $this->editorState->setFile(null);

            return new JsonResponse([
                'error' => $this->translator->trans(self::TRANSLATION_PREFIX . 'not_found', [], self::TRANSLATION_DOMAIN),
                'path' => $path,
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['path' => $path, 'content' => $content]);
    }

    /**
     * Makes a file the current one, without reading it: the editor fetches
     * the content itself once it hears of the change.
     */
    #[Route('/editor/file', name: 'app_editor_set_file', methods: ['POST'])]
    public function setFile(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValidFromHeader($request, 'file')) {
            return $this->stateErrorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $path = $request->request->get('path');
        if (!\is_string($path) || $path === '') {
            return $this->stateErrorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->fileReader->supports($path)) {
            return $this->stateErrorResponse('unsupported_file_type', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            // Whoever finds the file gone drops it — but only if it is the
            // current one, a bad path picked by hand must not clear it.
            if ($this->editorState->getFile() === $path) {
                $this->editorState->setFile(null);
            }

            return $this->stateErrorResponse('not_found', Response::HTTP_NOT_FOUND);
        }

        $this->editorState->setFile($realPath);

        return $this->stateResponse(['path' => $realPath]);
    }

    /**
     * New: no current file, so a reload doesn't bring the previous one back.
     */
    #[Route('/editor/file', name: 'app_editor_clear_file', methods: ['DELETE'])]
    public function clearFile(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValidFromHeader($request, 'file')) {
            return $this->stateErrorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $this->editorState->setFile(null);

        return $this->stateResponse([]);
    }

    /**
     * Content of the tree frame: loaded asynchronously so the column paints
     * before the folder has been walked, and reloaded after an in-app change
     * (Save as, delete, rename, change of current folder, refresh) — not a
     * filesystem watcher, see EDITOR_FOLDER_MODE.md. The walk is cached: see
     * OpenDirectoryTree.
     */
    #[Route('/editor/dir', name: 'app_editor_get_dir', methods: ['GET'])]
    public function getDir(OpenDirectoryTree $openDirectoryTree): Response
    {
        return $this->render('dir/tree.html.twig', [
            'tree_result' => $openDirectoryTree->build(),
        ]);
    }

    /**
     * Sets the current folder, which drops the current file: the editor must
     * not keep showing a file the new tree may not have. No filter on
     * location (see EDITOR_FOLDER_MODE.md).
     */
    #[Route('/editor/dir', name: 'app_editor_set_dir', methods: ['POST'])]
    public function setDir(Request $request, OpenDirectoryTree $openDirectoryTree): JsonResponse
    {
        if (!$this->isCsrfTokenValidFromHeader($request, 'dir')) {
            return $this->stateErrorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $path = $request->request->get('path');
        $realPath = \is_string($path) ? realpath($path) : false;
        if ($realPath === false || !is_dir($realPath)) {
            return $this->stateErrorResponse('invalid_directory', Response::HTTP_NOT_FOUND);
        }

        $this->editorState->setDir($realPath);
        $openDirectoryTree->forget();

        return $this->stateResponse(['path' => $realPath]);
    }

    /**
     * The refresh button of the tree: forgets the cached walk, so the frame
     * reload that follows sees what changed on disk outside the app. The
     * state doesn't change; it comes back like from every write.
     */
    #[Route('/editor/dir/refresh', name: 'app_editor_refresh_dir', methods: ['POST'])]
    public function refreshDir(Request $request, OpenDirectoryTree $openDirectoryTree): JsonResponse
    {
        if (!$this->isCsrfTokenValidFromHeader($request, 'dir')) {
            return $this->stateErrorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $openDirectoryTree->forget();

        return $this->stateResponse([]);
    }

    private function isCsrfTokenValidFromHeader(Request $request, string $id): bool
    {
        $token = $request->headers->get('X-CSRF-TOKEN');

        return \is_string($token) && $this->csrfTokenManager->isTokenValid(new CsrfToken($id, $token));
    }

    /**
     * @param array<string, string> $action what was done, as an object even when empty
     */
    private function stateResponse(array $action): JsonResponse
    {
        return new JsonResponse(['state' => $this->editorState->toArray(), 'action' => (object) $action]);
    }

    private function stateErrorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN),
            'state' => $this->editorState->toArray(),
        ], $status);
    }
}
