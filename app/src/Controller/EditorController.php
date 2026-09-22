<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\DocumentExtension;
use App\Exception\InvalidRequestException;
use App\Exception\Path\PathNotFoundException;
use App\Response\StateSuccessResponse;
use App\Service\EditorState;
use App\Enum\Setting\EditorMode;
use App\Service\Directory\OpenDirectoryTree;
use App\Service\Document\DocumentStore;
use App\Service\Path\PathPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Everything that reads or writes the EditorState lives under /editor. The
 * routes that write it return {state, action} whether or not they changed
 * anything, so the caller never has to guess (see EDITOR_REACTIVITY.md,
 * S5-S6). `action` is what was done, paths realpath'd. Every refusal is an
 * exception; UserFacingExceptionListener turns it into the state's refusal
 * form.
 */
final class EditorController extends AbstractController
{
    public function __construct(
        private readonly EditorState $editorState,
        private readonly DocumentStore $documentStore,
        private readonly PathPolicy $pathPolicy,
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
        // The marker /document/image gates on: this render is the page the
        // user actually opened, a third-party one never gets here (lot 02, SEC-04).
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
            throw new InvalidRequestException('invalid_mode');
        }

        $this->editorState->setMode($mode);

        return new StateSuccessResponse($this->editorState, ['mode' => $mode->value]);
    }

    /**
     * Makes a file the current one, without reading it for the editor: it
     * fetches the content itself once it hears of the change. The bytes are
     * still read once here, to refuse a file the editor could never show:
     * non-UTF-8 content would fail the JSON encoding on every later read
     * (lot 03-services-document.md, FIL-09). The state doesn't change on that
     * refusal, so the file shown before keeps opening after a reload.
     */
    #[Route('/editor/file', name: 'app_editor_set_file', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function setFile(Request $request): JsonResponse
    {
        $path = $request->request->get('path');
        if (!\is_string($path) || $path === '') {
            throw new InvalidRequestException('no_path');
        }

        if (!DocumentExtension::isDocument($path)) {
            throw new InvalidRequestException('unsupported_file_type');
        }

        try {
            $document = $this->documentStore->read($path);
        } catch (PathNotFoundException $e) {
            // Whoever finds the file gone drops it — but only if it is the
            // current one, a bad path picked by hand must not clear it.
            if ($this->editorState->getFile() === $path) {
                $this->editorState->setFile(null);
            }

            throw $e;
        }

        $this->editorState->setFile($document->path);

        return new StateSuccessResponse($this->editorState, ['path' => $document->path]);
    }

    /**
     * New: no current file, so a reload doesn't bring the previous one back.
     */
    #[Route('/editor/file', name: 'app_editor_clear_file', methods: ['DELETE'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function clearFile(): JsonResponse
    {
        $this->editorState->setFile(null);

        return new StateSuccessResponse($this->editorState, []);
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
            throw new PathNotFoundException(\is_string($path) ? $path : '');
        }

        $this->editorState->setDir($realPath);
        $openDirectoryTree->forget();

        return new StateSuccessResponse($this->editorState, ['path' => $realPath]);
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

        return new StateSuccessResponse($this->editorState, []);
    }
}
