<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\EditorState;
use App\Enum\Setting\EditorMode;
use App\File\MarkdownFileReader;
use App\File\MarkdownImageUrls;
use App\Service\Directory\OpenDirectoryTree;
use App\Service\Path\PathPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
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
        private readonly MarkdownImageUrls $markdownImageUrls,
        private readonly PathPolicy $pathPolicy,
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
     * what the routes don't send: `readonly`, always off at load.
     */
    #[Route('/editor', name: 'app_editor', methods: ['GET'])]
    public function index(): Response
    {
        // The marker /file/image gates on: this render is the page the user
        // actually opened, a third-party one never gets here (lot 02, SEC-04).
        $this->editorState->markOpened();

        $state = $this->editorState->toArray();

        return $this->render('editor/page.html.twig', [
            'mode' => $state['mode'],
            'state' => [...$state, 'readonly' => false],
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
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function setMode(Request $request): JsonResponse
    {
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
     * caller has no other way to know which one it was. The revision is the
     * hash of the raw bytes — it describes the file, not what the editor
     * shows of it — and travels with the content (lot 03-enregistrement.md,
     * FIL-03): save() compares against it before writing.
     */
    #[Route('/editor/file', name: 'app_editor_get_file', methods: ['GET'])]
    public function getFile(): JsonResponse
    {
        $path = $this->editorState->getFile();
        if ($path === null) {
            return new JsonResponse(['path' => null, 'content' => null]);
        }

        $raw = $this->fileReader->readRaw($path);
        if ($raw === null) {
            $this->editorState->setFile(null);

            return new JsonResponse([
                'error' => $this->translator->trans(self::TRANSLATION_PREFIX . 'not_found', [], self::TRANSLATION_DOMAIN),
                'path' => $path,
            ], Response::HTTP_NOT_FOUND);
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            // Same belt as setFile(): the file may have changed hands since
            // it was accepted. The state stays as it is, so the editor can
            // retry without a reload loop.
            return new JsonResponse([
                'error' => $this->translator->trans(self::TRANSLATION_PREFIX . 'not_utf8', [], self::TRANSLATION_DOMAIN),
            ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        return new JsonResponse([
            'path' => $path,
            'content' => $this->markdownImageUrls->toServiceUrls($raw),
            'revision' => hash('xxh128', $raw),
        ]);
    }

    /**
     * Makes a file the current one, without reading it for the editor: it
     * fetches the content itself once it hears of the change. The bytes are
     * still read once here, to refuse a file the editor could never show:
     * non-UTF-8 content would fail the JSON encoding on every later read
     * (lot 03-enregistrement.md, FIL-09). The state doesn't change on that
     * refusal, so the file shown before keeps opening after a reload.
     */
    #[Route('/editor/file', name: 'app_editor_set_file', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function setFile(Request $request): JsonResponse
    {
        $path = $request->request->get('path');
        if (!\is_string($path) || $path === '') {
            return $this->stateErrorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->fileReader->supports($path)) {
            return $this->stateErrorResponse('unsupported_file_type', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $realPath = $this->pathPolicy->read($path);
        $raw = $realPath !== null ? $this->fileReader->readRaw($realPath) : null;
        if ($raw === null) {
            // Whoever finds the file gone drops it — but only if it is the
            // current one, a bad path picked by hand must not clear it.
            if ($this->editorState->getFile() === $path) {
                $this->editorState->setFile(null);
            }

            return $this->stateErrorResponse('not_found', Response::HTTP_NOT_FOUND);
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            return $this->stateErrorResponse('not_utf8', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $this->editorState->setFile($realPath);

        return $this->stateResponse(['path' => $realPath]);
    }

    /**
     * New: no current file, so a reload doesn't bring the previous one back.
     */
    #[Route('/editor/file', name: 'app_editor_clear_file', methods: ['DELETE'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function clearFile(): JsonResponse
    {
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
        $dir = $this->editorState->getDir();
        $treeResult = $openDirectoryTree->build();
        // build() drops a folder it found gone: the tree would say "no
        // folder" like any other, the anomaly tells what really happened.
        $goneDir = $dir !== null && $this->editorState->getDir() === null ? $dir : null;

        return $this->render('dir/tree.html.twig', [
            'tree_result' => $treeResult,
            'gone_dir' => $goneDir,
        ]);
    }

    /**
     * Sets the current folder, which drops the current file: the editor must
     * not keep showing a file the new tree may not have. No filter on
     * location (see EDITOR_FOLDER_MODE.md).
     */
    #[Route('/editor/dir', name: 'app_editor_set_dir', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function setDir(Request $request, OpenDirectoryTree $openDirectoryTree): JsonResponse
    {
        $path = $request->request->get('path');
        $realPath = \is_string($path) ? $this->pathPolicy->list($path) : null;
        if ($realPath === null) {
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
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function refreshDir(Request $request, OpenDirectoryTree $openDirectoryTree): JsonResponse
    {
        $openDirectoryTree->forget();

        return $this->stateResponse([]);
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
