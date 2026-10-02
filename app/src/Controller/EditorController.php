<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Editor\OpenPathRequest;
use App\Dto\Editor\SetDirRequest;
use App\Dto\Editor\SetFileRequest;
use App\Dto\Editor\SetModeRequest;
use App\Response\StateSuccessResponse;
use App\Service\Directory\OpenDirectoryTree;
use App\Service\Editor\EditorNavigator;
use App\Service\Editor\EditorState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Everything that reads or writes the EditorState lives under /editor. The
 * routes that write it return {state, action} whether or not they changed
 * anything, so the caller never has to guess (see EDITOR_REACTIVITY.md,
 * S5-S6). `action` is what was done, paths realpath'd. Every refusal is an
 * exception; UserFacingExceptionListener turns it into the state's refusal
 * form. The writes themselves go through EditorNavigator (lot
 * 05-editor-navigator.md); this class only reads the state and builds
 * responses.
 */
final class EditorController extends AbstractController
{
    public function __construct(
        private readonly EditorState $editorState,
        private readonly EditorNavigator $editorNavigator,
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
    public function setMode(#[MapRequestPayload(mapWhenEmpty: true)] SetModeRequest $payload): JsonResponse
    {
        \assert($payload->mode !== null);

        $this->editorNavigator->setMode($payload->mode);

        return new StateSuccessResponse($this->editorState, ['mode' => $payload->mode->value]);
    }

    /**
     * Makes a file the current one, without reading it for the editor: it
     * fetches the content itself once it hears of the change. A relative
     * path — a link followed in the document (EDITOR_LINKS.md) — resolves
     * against the folder of the current file, passed as the anchor.
     */
    #[Route('/editor/file', name: 'app_editor_set_file', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function setFile(#[MapRequestPayload(mapWhenEmpty: true)] SetFileRequest $payload): JsonResponse
    {
        $realPath = $this->editorNavigator->openFile($payload->path, $this->editorState->getFile());

        return new StateSuccessResponse($this->editorState, ['path' => $realPath]);
    }

    /**
     * New: no current file, so a reload doesn't bring the previous one back.
     */
    #[Route('/editor/file', name: 'app_editor_clear_file', methods: ['DELETE'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function clearFile(): JsonResponse
    {
        $this->editorNavigator->closeFile();

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
    public function setDir(#[MapRequestPayload(mapWhenEmpty: true)] SetDirRequest $payload): JsonResponse
    {
        $realPath = $this->editorNavigator->openDir($payload->path);

        return new StateSuccessResponse($this->editorState, ['path' => $realPath]);
    }

    /**
     * Opens a path without knowing in advance what it is (lot 04a): the
     * server looks, switches the mode and sets the target — folder or file —
     * in one write, refused without touching the state. Nothing in the
     * interface asks for it yet; the Hub will (lot 04b).
     */
    #[Route('/editor/open', name: 'app_editor_open', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function openPath(#[MapRequestPayload(mapWhenEmpty: true)] OpenPathRequest $payload): JsonResponse
    {
        $opened = $this->editorNavigator->openPath($payload->path);

        return new StateSuccessResponse($this->editorState, ['path' => $opened->path, 'openMode' => $opened->mode->value]);
    }

    /**
     * The refresh button of the tree: forgets the cached walk, so the frame
     * reload that follows sees what changed on disk outside the app. The
     * state doesn't change; it comes back like from every write.
     */
    #[Route('/editor/dir/refresh', name: 'app_editor_refresh_dir', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function refreshDir(): JsonResponse
    {
        $this->editorNavigator->refreshDir();

        return new StateSuccessResponse($this->editorState, []);
    }
}
