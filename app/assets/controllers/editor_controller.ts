import { Controller } from '@hotwired/stimulus';
import type { Crepe } from '@milkdown/crepe';
import { abortAICmd } from '@milkdown/crepe/feature/ai';
import { EditorStatus, commandsCtx, editorViewCtx } from '@milkdown/kit/core';
import type { Ctx } from '@milkdown/kit/ctx';
import { imageBlockSchema } from '@milkdown/kit/component/image-block';
import { clearDiffReviewCmd, diffPluginKey } from '@milkdown/kit/plugin/diff';
import { addBlockTypeCommand, clearTextInCurrentBlockCommand } from '@milkdown/kit/preset/commonmark';
import { streamingPluginKey } from '@milkdown/kit/plugin/streaming';
import { DOMSerializer } from '@milkdown/kit/prose/model';
import { replaceAll } from '@milkdown/utils';
import AiClient from '../editor/ai-client';
import EditorFactory from '../editor/editor-factory';
import { type EditorState, emit, on } from '../editor/events';
import { basename } from '../editor/file-entries';
import { confirmDialog } from '../utils/confirm-dialog';
import { saveConflictDialog } from '../utils/conflict-dialog';
import { pickPath, savePath } from '../utils/tauri';
import { showToast } from '../utils/toast';
import type EditorStateController from './editor_state_controller';

/**
 * Editor::getAiConfig(): the hub and topic, always given. Whether the AI is on
 * is the `ai_enabled` of the state, which can change without a reload.
 */
interface AiConfig {
    mercureUrl?: string;
    topic?: string;
}

interface Urls {
    file: string;
    copy: string;
    image: string;
    aiSubscribe: string;
    aiInstruct: string;
    aiAbort: string;
}

/** Controller-owned texts; the editor's own ones go straight to EditorFactory. */
interface I18n {
    untitled?: string;
    toggle?: { edit?: string; readonly?: string };
    unsaved?: { confirm?: string; cancel?: string; continue?: string };
    conflict?: { question?: string; cancel?: string; saveAs?: string; overwrite?: string };
    loadError?: string;
    toast?: {
        saved?: string;
        savedAs?: string;
        copiedMarkdown?: string;
        copyMarkdownFailed?: string;
        copiedCode?: string;
        copyCodeFailed?: string;
        currentFileDeleted?: string;
        currentFileGone?: string;
    };
    ai?: { requestFailed?: string };
    [key: string]: unknown;
}

/** GET /editor/file: the current file with its revision, none, or `{error, path}` on a 404. */
interface FileResponse {
    path?: string | null;
    content?: string | null;
    revision?: string | null;
    error?: string;
}

/** The one save in flight, if any (FRT-01, lot 03): its answer, whatever its order, concludes exactly it. */
interface InFlightSave {
    action: 'do-save' | 'do-save_as';
    path: string;
    markdown: string;
    /** What the file was on disk when it was read; the server checks it (409). */
    revision: string | null;
}

/**
 * The editor. Invariant: it shows the current file of the state. It reads
 * that file itself (GET /editor/file) — at start from the master's outlet,
 * then on every `nav-change_file-succeeded`, even for the file already
 * current: picking it again past the leave guard means going back to disk
 * (S10). It asks the master for its writes (New, Save, Save as) and follows
 * everyone else's: it empties when the user asks for another document (a
 * switch of mode or folder, New), and on an anomaly — its file deleted or
 * gone — it keeps the text and only drops the path (lot 03); it relabels
 * when its file is renamed (see EDITOR_REACTIVITY.md).
 *
 * The AI is on or off with the state's `ai_enabled`, read at load and on the
 * settings actions that can change it. Crepe fixes its features when it is
 * created, so a change recreates it around the same markdown (see
 * EDITOR_SETTINGS.md).
 *
 * The leave guard runs on window, capture phase, before any `…-requested`:
 * the master only ever sees confirmed requests (S11).
 */
export default class extends Controller<HTMLElement> {
    static values = {
        fileCsrfToken: String,
        aiCsrfToken: String,
        urls: Object,
        aiConfig: { type: Object, default: {} },
        readonly: { type: Boolean, default: false },
        // Defaults for the editor's own texts (placeholder, slash menu, AI panel)
        // live in EditorFactory; only controller-owned UI text falls back here.
        i18n: Object,
    };

    static targets = ['saveButton', 'saveAsButton', 'printButton', 'copyMarkdownButton', 'toggleButton', 'toggleLabel', 'dirtyIndicator', 'filePath', 'loadErrorMessage'];

    static outlets = ['editor-state'];

    declare readonly fileCsrfTokenValue: string;
    declare readonly aiCsrfTokenValue: string;
    declare readonly urlsValue: Urls;
    declare readonly aiConfigValue: AiConfig;
    declare readonly readonlyValue: boolean;
    declare readonly i18nValue: I18n;

    declare readonly hasSaveButtonTarget: boolean;
    declare readonly saveButtonTarget: HTMLButtonElement;
    declare readonly hasSaveAsButtonTarget: boolean;
    declare readonly saveAsButtonTarget: HTMLButtonElement;
    declare readonly hasPrintButtonTarget: boolean;
    declare readonly printButtonTarget: HTMLButtonElement;
    declare readonly hasCopyMarkdownButtonTarget: boolean;
    declare readonly copyMarkdownButtonTarget: HTMLButtonElement;
    declare readonly hasToggleLabelTarget: boolean;
    declare readonly toggleLabelTarget: HTMLElement;
    declare readonly hasDirtyIndicatorTarget: boolean;
    declare readonly dirtyIndicatorTarget: HTMLElement;
    declare readonly hasFilePathTarget: boolean;
    declare readonly filePathTarget: HTMLElement;
    declare readonly hasLoadErrorMessageTarget: boolean;
    declare readonly loadErrorMessageTarget: HTMLElement;

    #crepe: Crepe | null = null;
    // The outlet can connect before connect() has even started (Stimulus
    // starts the outlet observer first): it waits on this.
    #crepeReady!: Promise<Crepe>;
    #resolveCrepe!: (crepe: Crepe) => void;
    // Crepe is created once the state is known: it says whether the AI is on.
    #stateReady!: Promise<EditorState>;
    #resolveState!: (state: EditorState) => void;
    // What Crepe was created with.
    #aiEnabled = false;
    // Set while Crepe is being recreated for a change of `ai_enabled`.
    #recreation: Promise<void> | null = null;
    #currentPath: string | null = null;
    // Save as opens its dialog in the current folder, in dir mode only.
    #directory: string | null = null;
    #savedRef = '';
    // The one save in flight, if any: the buttons stay disabled until its
    // answer (FRT-01, lot 03).
    #inFlightSave: InFlightSave | null = null;
    // The revision of the loaded file, as GET /editor/file gave it: save()
    // sends it back, and the server refuses a write on a stale one (409).
    #revision: string | null = null;
    // From the moment the editor empties for a load until the file lands:
    // the document is not editable meanwhile (FRT-04).
    #loadingFile = false;
    // The load failed for something else than an abort: the explicit error
    // state, with Retry (FRT-04).
    #loadFailed = false;
    #isReadonly = false;
    #printCopy: HTMLElement | null = null;
    #aiClient: AiClient | null = null;
    #fileRequest: AbortController | null = null;
    #leaveConfirmed = false;
    #unsubscribers: Array<() => void> = [];

    #onBeforePrint = (): void => this.#mountPrintCopy();
    #onAfterPrint = (): void => this.#removePrintCopy();
    #onGuardedClick = (event: MouseEvent): void => this.#guardLeave(event);

    initialize(): void {
        this.#crepeReady = new Promise((resolve) => (this.#resolveCrepe = resolve));
        this.#stateReady = new Promise((resolve) => (this.#resolveState = resolve));
    }

    // FRT-11, lot Front éditeur: Stimulus never awaits connect(); a rejection
    // leaves the editor empty with no message.
    // eslint-disable-next-line @typescript-eslint/no-misused-promises
    async connect(): Promise<void> {
        this.#isReadonly = this.readonlyValue;

        const { ai_enabled: aiEnabled } = await this.#stateReady;
        this.#aiEnabled = aiEnabled;
        this.#connectAiClient();

        const crepe = await this.#createCrepe();
        this.#crepe = crepe;

        // A new document is clean: capture the empty editor's markdown as the
        // reference so the indicator doesn't fire on the initial content.
        this.#savedRef = crepe.getMarkdown();

        window.addEventListener('beforeprint', this.#onBeforePrint);
        window.addEventListener('afterprint', this.#onAfterPrint);
        // Capture phase, on window: covers the sidebar (mode-single / mode-dir),
        // not just this element, since navigation there also drops unsaved work.
        window.addEventListener('click', this.#onGuardedClick, true);
        this.#listen();

        if (this.#isReadonly) {
            this.#applyReadonlyState();
        }

        this.#updateSaveButton('');
        this.#updatePrintButton('');
        this.#updateCopyMarkdownButton('');
        this.#updateDirtyIndicator(this.#savedRef);
        this.#updateFilePath();

        this.#resolveCrepe(crepe);
    }

    disconnect(): void {
        window.removeEventListener('beforeprint', this.#onBeforePrint);
        window.removeEventListener('afterprint', this.#onAfterPrint);
        window.removeEventListener('click', this.#onGuardedClick, true);
        this.#unsubscribers.forEach((unsubscribe) => unsubscribe());
        this.#unsubscribers = [];
        this.#fileRequest?.abort();
        this.#removePrintCopy();
        this.#aiClient?.close();
        // FRT-11, lot Front éditeur: destroy() is not awaited; the editor can
        // be torn down while it is still cleaning up.
        // eslint-disable-next-line @typescript-eslint/no-floating-promises
        this.#crepe?.destroy();
        this.#crepe = null;
        this.initialize();
    }

    /**
     * The state at load. No file in the page: the editor reads it itself,
     * through the same path as after an event.
     */
    async editorStateOutletConnected(outlet: EditorStateController): Promise<void> {
        const { state } = outlet;
        this.#resolveState({ ...state });
        await this.#crepeReady;
        this.#syncDirectory(state);
        if (state.file !== null) {
            this.#beginLoad();
        }
    }

    #listen(): void {
        this.#unsubscribers = [
            // Changing mode or folder drops the current file server side.
            on('editor:nav-switch_mode-succeeded', ({ state }) => this.#resetTo(state)),
            on('editor:nav-change_dir-succeeded', ({ state }) => this.#resetTo(state)),

            // Last action wins: a file still loading must not land after the
            // one asked for now. The editor keeps showing its file meanwhile.
            on('editor:nav-change_file-requested', () => this.#fileRequest?.abort()),
            on('editor:nav-change_file-succeeded', ({ state }) => {
                this.#syncDirectory(state);
                this.#beginLoad();
            }),
            // The file asked for may be gone: the state then has none. Asking
            // again for the current file and finding it gone is an anomaly —
            // the text stays, only the path falls (lot 03); any other file
            // was an intention to leave, leave guard included.
            on('editor:nav-change_file-failed', ({ state, action }) => {
                if (action.path === this.#currentPath && state.file === null) {
                    this.#currentFileGone(this.#fileGoneMessage(this.i18nValue.toast?.currentFileGone, action.path));
                } else if (state.file === null && this.#currentPath !== null) {
                    this.#resetTo(state);
                }
            }),

            // New empties at once, whoever asked; the answer has nothing more
            // to say — unless it failed and the state still has a file, which
            // is then what the editor shows again.
            on('editor:nav-new_file-requested', () => this.#reset()),
            on('editor:nav-new_file-failed', ({ state }) => {
                if (state.file !== null) {
                    this.#beginLoad();
                }
            }),

            on('editor:do-save-succeeded', ({ action }) => this.#saved(this.i18nValue.toast?.saved ?? 'File saved', action.revision)),
            on('editor:do-save-failed', ({ action }) => this.#saveFailed(action.status, action.message ?? null)),
            on('editor:do-save_as-succeeded', ({ state, action }) => {
                // The server made the new file the current one, realpath'd.
                this.#currentPath = action.path;
                this.#syncDirectory(state);
                this.#updateFilePath();
                const template = this.i18nValue.toast?.savedAs ?? 'File saved as {name}';
                this.#saved(template.replace('{name}', basename(action.path)), action.revision);
            }),
            on('editor:do-save_as-failed', ({ action }) => this.#saveFailed(action.status, action.message ?? null)),

            // An archive that opened a file shows it as a file the user opened;
            // one that opened a folder empties the editor, as choosing a folder
            // does. One that opened nothing leaves everything as it was.
            on('editor:do-import-succeeded', ({ state, action }) => {
                if (action.openMode === 'single') {
                    this.#syncDirectory(state);
                    this.#beginLoad();
                } else if (action.openMode === 'dir') {
                    this.#resetTo(state);
                }
            }),

            // Deleting the current file is an anomaly, not an intention: the
            // text stays and the path falls, and the toast says what happened
            // and how to keep the text — the sidebar's says "deleted" (lot 03).
            on('editor:do-delete-succeeded', ({ action }) => {
                if (action.path === this.#currentPath) {
                    this.#currentFileGone(this.#fileGoneMessage(this.i18nValue.toast?.currentFileDeleted, action.path));
                }
            }),
            on('editor:do-rename-succeeded', ({ action }) => {
                if (action.oldPath !== this.#currentPath) {
                    return;
                }
                this.#currentPath = action.newPath;
                this.#updateSaveButton(this.#crepe?.getMarkdown());
                this.#updateFilePath();
            }),

            // Saving the settings, setting or deleting a key can turn the AI on or off.
            on('editor:do-save_settings-succeeded', ({ state }) => void this.#followAi(state)),
            on('editor:do-set_key-succeeded', ({ state }) => void this.#followAi(state)),
            on('editor:do-delete_key-succeeded', ({ state }) => void this.#followAi(state)),

            // Same anomaly on a re-read: the file was there, the re-read says
            // it isn't anymore (lot 03).
            on('editor:state-resynced', ({ anomaly }) => {
                if (anomaly.file !== undefined && anomaly.file === this.#currentPath) {
                    this.#currentFileGone(this.#fileGoneMessage(this.i18nValue.toast?.currentFileGone, anomaly.file));
                }
            }),

            // The delete dialog asks before showing its single question, so
            // the question can also warn about the unsaved current file (lot 03).
            on('editor:unsaved-file-query', (query) => {
                if (query.path === this.#currentPath && this.#isDirty()) {
                    query.unsaved = true;
                }
            }),
        ];
    }

    async #createCrepe(defaultValue = ''): Promise<Crepe> {
        const crepe = await EditorFactory.create({
            root: this.element,
            defaultValue,
            i18n: this.i18nValue,
            // FRT-11, lot Front éditeur: both callbacks are async and run
            // unwatched; their rejections are unobserved.
            // eslint-disable-next-line @typescript-eslint/no-misused-promises
            onInsertImage: (ctx: Ctx) => this.#insertImageFromPicker(ctx),
            // eslint-disable-next-line @typescript-eslint/no-misused-promises
            onCopyCode: (text: string) => this.#copyCode(text),
            aiEnabled: this.#aiEnabled,
            aiProvider: this.#aiClient?.createProvider(),
            // Crepe prefixes the message ("AI provider error: ..."); show the original one.
            onAiError: (error: Error) => showToast('error', (error.cause as Error | undefined)?.message ?? error.message),
        });

        this.#wrapScroll();

        crepe.on((listener) => {
            listener.markdownUpdated((_ctx, markdown) => {
                this.#updateSaveButton(markdown);
                this.#updatePrintButton(markdown);
                this.#updateCopyMarkdownButton(markdown);
                this.#updateDirtyIndicator(markdown);
            });
        });

        return crepe;
    }

    /** The AI client, once the hub and topic are known: only with AI on. */
    #connectAiClient(): void {
        const { mercureUrl, topic } = this.aiConfigValue;
        if (!this.#aiEnabled || mercureUrl === undefined || topic === undefined) {
            return;
        }

        this.#aiClient = new AiClient({
            csrfToken: this.aiCsrfTokenValue,
            urls: { subscribe: this.urlsValue.aiSubscribe, instruct: this.urlsValue.aiInstruct, abort: this.urlsValue.aiAbort },
            mercureUrl,
            topic,
            requestFailedMessage: this.i18nValue.ai?.requestFailed,
        });
    }

    /**
     * If the state's `ai_enabled` is no longer what Crepe was created with,
     * recreates it. One at a time: a change met meanwhile waits for the
     * running one, then compares again.
     */
    #followAi(state: EditorState): Promise<void> {
        const run = async (): Promise<void> => {
            await this.#recreation;
            if (state.ai_enabled === this.#aiEnabled || this.#crepe === null) {
                return;
            }
            await this.#recreateCrepe(state.ai_enabled);
        };
        const pending = run();
        this.#recreation = pending.finally(() => {
            if (this.#recreation === pending) {
                this.#recreation = null;
            }
        });

        return pending;
    }

    /**
     * Extracts the markdown, recreates Crepe with or without the AI feature,
     * puts the markdown back. Nothing is lost, so no leave guard. The file,
     * the read-only mode and the "unsaved" state stay; the undo history
     * starts over, as when a file is opened.
     */
    async #recreateCrepe(aiEnabled: boolean): Promise<void> {
        const previous = this.#crepe!;
        const markdown = previous.getMarkdown();
        const wasDirty = this.#isDirty(markdown);

        // A generation in progress dies with the editor it runs in.
        this.#discardAi();

        this.#crepe = null;
        await previous.destroy();
        // Crepe leaves its empty container behind; the next one makes its own.
        this.element.querySelectorAll(':scope > .milkdown').forEach((container) => container.remove());
        this.#aiClient?.close();
        this.#aiClient = null;

        this.#aiEnabled = aiEnabled;
        this.#connectAiClient();

        const crepe = await this.#createCrepe(markdown);
        this.#crepe = crepe;
        // A clean document stays clean even if Crepe serializes it a little
        // differently the second time.
        if (!wasDirty) {
            this.#savedRef = crepe.getMarkdown();
        }
        if (this.#isReadonly) {
            this.#applyReadonlyState();
        }
        this.#updateSaveButton(crepe.getMarkdown());
        this.#updatePrintButton(crepe.getMarkdown());
        this.#updateCopyMarkdownButton(crepe.getMarkdown());
        this.#updateDirtyIndicator();
    }

    toggleReadonly(): void {
        this.#isReadonly = !this.#isReadonly;
        this.#applyReadonlyState();
    }

    #applyReadonlyState(): void {
        this.#applyEditable();
        this.#updateSaveButton(this.#crepe?.getMarkdown());

        if (this.hasToggleLabelTarget) {
            this.toggleLabelTarget.textContent = this.#isReadonly
                ? this.i18nValue.toggle?.edit ?? 'Edit'
                : this.i18nValue.toggle?.readonly ?? 'Read only';
        }
    }

    /**
     * The document is editable only in edit mode, and only once what it shows
     * is really there: not while the file loads (the user already chose to
     * leave the previous one), not when its load failed (FRT-04, lot 03).
     */
    #applyEditable(): void {
        const editable = !this.#isReadonly && !this.#loadingFile && !this.#loadFailed;
        const prosemirror = this.element.querySelector('.ProseMirror');
        prosemirror?.setAttribute('contenteditable', editable ? 'true' : 'false');
        this.element.classList.toggle('is-readonly', this.#isReadonly);
    }

    #syncDirectory(state: EditorState): void {
        this.#directory = state.mode === 'dir' ? state.dir : null;
    }

    /**
     * Leaves the old document — loading it is already an intention — and
     * reads the current file: the editor is empty and not editable until it
     * lands, or until it fails into the explicit error state (FRT-04, lot 03).
     */
    #beginLoad(): void {
        this.#reset();
        this.#loadingFile = true;
        this.#applyEditable();
        void this.#loadCurrentFile();
    }

    /**
     * GET /editor/file. The last read wins: one still in flight is aborted,
     * so a stale answer can't land after the right one. A 404 means the
     * current file is gone: the server already dropped it, the master hears
     * it as an anomaly and tells everyone else. Any other failure shows the
     * error state: never a document that no longer matches the state.
     */
    async #loadCurrentFile(): Promise<void> {
        this.#fileRequest?.abort();
        const request = new AbortController();
        this.#fileRequest = request;

        try {
            const response = await fetch(this.urlsValue.file, { signal: request.signal });
            const data: FileResponse = await response.json().catch(() => ({}));

            if (response.status === 404 && data.path) {
                this.#reset();
                emit('editor:state-anomaly-reported', { anomaly: { file: data.path } });
                showToast('error', data.error || 'File not found');

                return;
            }

            if (!response.ok) {
                this.#failLoad(data.error ?? null);

                return;
            }

            // Crepe may be mid-recreation: the file lands in the new one.
            await this.#recreation;
            if (request.signal.aborted) {
                return;
            }

            if (data.path === null || data.path === undefined) {
                this.#reset();
            } else {
                this.#applyLoadedFile(data.path, data.content ?? '', data.revision ?? null);
            }
        } catch (err) {
            if (err instanceof DOMException && err.name === 'AbortError') {
                return;
            }
            console.error('Failed to open file:', err);
            this.#failLoad((err as Error).message || null);
        } finally {
            if (this.#fileRequest === request) {
                this.#fileRequest = null;
            }
        }
    }

    #applyLoadedFile(path: string, content: string, revision: string | null): void {
        const crepe = this.#crepe!;
        this.#loadingFile = false;
        this.#loadFailed = false;
        this.element.classList.remove('is-load-failed');
        this.#currentPath = path;
        this.#revision = revision;
        this.#replaceDocument(crepe, content);
        this.#savedRef = crepe.getMarkdown();
        this.#applyEditable();
        this.#updateSaveButton(this.#savedRef);
        this.#updateDirtyIndicator(this.#savedRef);
        this.#updateFilePath();
    }

    /**
     * New: the current file becomes none, server side too, or a reload would
     * bring the previous one back. The editor empties on its own request.
     */
    newFile(): void {
        emit('editor:nav-new_file-requested', { action: {} });
    }

    #resetTo(state: EditorState): void {
        this.#syncDirectory(state);
        this.#reset();
    }

    /**
     * Empties the editor, with no call to the server: the state already has
     * no current file, or is about to.
     */
    #reset(): void {
        // Last action wins: a file still loading must not replace the new one.
        this.#fileRequest?.abort();
        this.#loadingFile = false;
        this.#loadFailed = false;
        this.element.classList.remove('is-load-failed');
        this.#revision = null;
        const crepe = this.#crepe;
        if (crepe === null) {
            return;
        }
        this.#replaceDocument(crepe, '');
        this.#currentPath = null;
        this.#savedRef = crepe.getMarkdown();
        this.#applyEditable();
        this.#updateSaveButton(this.#savedRef);
        this.#updatePrintButton(this.#savedRef);
        this.#updateCopyMarkdownButton(this.#savedRef);
        this.#updateDirtyIndicator(this.#savedRef);
        this.#updateFilePath();
    }

    /**
     * Another document, not an edit of this one: a fresh ProseMirror state
     * (flush), so undo cannot bring the previous file back. Without flush,
     * in the hub's WebKitGTK (no overflow-anchor), ProseMirror keeps a
     * reference node in place across the replacement and scrolls every
     * parent a few pixels down.
     *
     * The flush recreates every plugin view, Milkdown's mounting one too:
     * .milkdown is rebuilt, the scroll wrapper goes with it, and a new one
     * starts at the top.
     */
    #replaceDocument(crepe: Crepe, markdown: string): void {
        // IA-04, lot 03: first and unconditional — no path may replace the
        // document and leave a generation running on the old one.
        this.#discardAi();
        crepe.editor.action(replaceAll(markdown, true));
        this.#wrapScroll();
    }

    /**
     * Wraps .ProseMirror so the scrollable area extends past it, over the
     * surrounding padding/desk background too, not just the editable sheet.
     */
    #wrapScroll(): void {
        const prosemirror = this.element.querySelector('.ProseMirror');
        if (!prosemirror || prosemirror.parentElement?.classList.contains('editor-content')) {
            return;
        }
        const content = document.createElement('div');
        content.className = 'editor-content';
        prosemirror.replaceWith(content);
        content.appendChild(prosemirror);
    }

    saveFile(): void {
        // Without a path there is nothing to save to: Save as is the CTA for
        // that, and the button is grayed (lot 03, reversed on Arnaud's
        // feedback: two CTAs doing the same thing only confuse).
        if (this.#isReadonly || this.#inFlightSave !== null || this.#crepe === null || this.#currentPath === null) {
            return;
        }

        const markdown = this.#crepe.getMarkdown();
        // Save follows the dirty state, not the presence of text (FIL-07):
        // a clean document has nothing to write that isn't on disk already.
        if (!this.#isDirty(markdown)) {
            return;
        }

        this.#beginSave('do-save', this.#currentPath, markdown, this.#revision);
    }

    async saveFileAs(): Promise<void> {
        if (this.#isReadonly || this.#inFlightSave !== null) {
            return;
        }

        const defaultName = this.#currentPath !== null ? basename(this.#currentPath) : 'untitled.md';
        // In dir mode, the save dialog opens in the current directory without
        // constraining where the file actually gets saved (see EDITOR_FOLDER_MODE.md).
        const path = await savePath(defaultName, this.#directory);
        // The picker can outlast a change of mind: everything is re-read after.
        if (path === null || this.#crepe === null || this.#isReadonly || this.#inFlightSave !== null) {
            return;
        }

        this.#beginSave('do-save_as', path, this.#crepe.getMarkdown(), null);
    }

    /**
     * One save at a time (FRT-01, lot 03): the in-flight object is what the
     * answer concludes, whatever its order. Save as never sends a revision —
     * the native picker already confirmed the overwrite; a null revision on
     * do-save is Écraser after a conflict: the server writes blind.
     */
    #beginSave(action: 'do-save' | 'do-save_as', path: string, markdown: string, revision: string | null): void {
        this.#inFlightSave = { action, path, markdown, revision };
        this.#updateSaveButton(markdown);

        if (action === 'do-save' && revision !== null) {
            emit('editor:do-save-requested', { action: { path, content: markdown, revision } });

            return;
        }

        if (action === 'do-save') {
            emit('editor:do-save-requested', { action: { path, content: markdown } });

            return;
        }

        emit('editor:do-save_as-requested', { action: { path, content: markdown } });
    }

    #saved(message: string, revision: string): void {
        const save = this.#inFlightSave;
        this.#inFlightSave = null;
        if (save !== null) {
            this.#savedRef = save.markdown;
        }
        this.#revision = revision;
        this.#updateSaveButton(this.#crepe?.getMarkdown());
        this.#updateDirtyIndicator();
        showToast('success', message);
    }

    /**
     * A save that failed without a server answer (the network) only frees
     * the buttons — the master already toasted. A 409 opens the conflict
     * dialog: Enregistrer sous, or Écraser — the same save again, without a
     * revision, on fresh markdown (lot 03).
     */
    #saveFailed(status: number | undefined, message: string | null): void {
        const save = this.#inFlightSave;
        this.#inFlightSave = null;
        this.#updateSaveButton(this.#crepe?.getMarkdown());

        if (status !== 409 || save === null) {
            return;
        }

        void saveConflictDialog({
            message,
            question: this.i18nValue.conflict?.question ?? 'The file changed outside of Papermark. What happens to your changes?',
            cancelLabel: this.i18nValue.conflict?.cancel ?? 'Cancel',
            saveAsLabel: this.i18nValue.conflict?.saveAs ?? 'Save as',
            overwriteLabel: this.i18nValue.conflict?.overwrite ?? 'Overwrite',
        }).then((choice) => {
            if (choice === 'save_as') {
                void this.saveFileAs();
            } else if (choice === 'overwrite') {
                this.#beginSave('do-save', save.path, this.#crepe?.getMarkdown() ?? save.markdown, null);
            }
        });
    }

    /** The Retry of the load error state: the same read, the same intention. */
    retryLoad(): void {
        if (!this.#loadFailed) {
            return;
        }

        this.#loadFailed = false;
        this.#loadingFile = true;
        this.element.classList.remove('is-load-failed');
        this.#applyEditable();
        this.#updateSaveButton('');
        void this.#loadCurrentFile();
    }

    /**
     * The explicit error state (FRT-04): no document that no longer matches
     * the state, and no toast — the state is the display, and it carries Retry.
     */
    #failLoad(message: string | null): void {
        this.#loadingFile = false;
        this.#loadFailed = true;
        this.element.classList.add('is-load-failed');
        if (this.hasLoadErrorMessageTarget) {
            this.loadErrorMessageTarget.textContent = message ?? this.i18nValue.loadError ?? 'Could not load the file';
        }
        this.#updateSaveButton('');
        this.#applyEditable();
    }

    /**
     * Anomaly (lot 03): the current file is gone or was deleted, but nobody
     * asked for the text to go. It stays, the path falls, the document
     * becomes an untitled one, and the toast says how to keep it.
     */
    #currentFileGone(message: string): void {
        this.#discardAi();
        this.#currentPath = null;
        this.#revision = null;
        this.#updateSaveButton(this.#crepe?.getMarkdown());
        this.#updateDirtyIndicator();
        this.#updateFilePath();
        showToast('error', message);
    }

    #fileGoneMessage(template: string | undefined, path: string): string {
        return (template ?? 'The file "{name}" is gone: its content stays open, unsaved. Save it under another name to keep it.')
            .replace('{name}', basename(path));
    }

    printFile(): void {
        // beforeprint also mounts it; mounting here too doesn't rely on the
        // webview firing that event for a scripted print.
        this.#mountPrintCopy();
        window.print();
    }

    async copyMarkdown(): Promise<void> {
        const markdown = this.#crepe?.getMarkdown() ?? '';
        try {
            const content = await this.#convertImageUrlsForCopy(markdown);
            await navigator.clipboard.writeText(content);
            showToast('success', this.i18nValue.toast?.copiedMarkdown ?? 'Markdown copied to clipboard');
        } catch (err) {
            console.error('Failed to copy markdown:', err);
            showToast('error', this.i18nValue.toast?.copyMarkdownFailed ?? 'Failed to copy markdown');
        }
    }

    async #copyCode(text: string): Promise<void> {
        try {
            await navigator.clipboard.writeText(text);
            showToast('success', this.i18nValue.toast?.copiedCode ?? 'Code copied to clipboard');
        } catch (err) {
            console.error('Failed to copy code:', err);
            showToast('error', this.i18nValue.toast?.copyCodeFailed ?? 'Failed to copy code');
        }
    }

    /**
     * The editor only ever holds /file/image service URLs for local images
     * (see EDITOR_IMAGES.md); the server converts them back to their raw
     * path before the markdown leaves the editor, same as save().
     */
    async #convertImageUrlsForCopy(markdown: string): Promise<string> {
        const formData = new FormData();
        formData.append('content', markdown);

        const response = await fetch(this.urlsValue.copy, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': this.fileCsrfTokenValue },
            body: formData,
        });

        if (!response.ok) {
            throw new Error(`Copy failed: ${response.status}`);
        }

        const { content } = await response.json();

        return content;
    }

    /**
     * Prints a serialized copy of the document instead of the editor DOM:
     * schema toDOM output (plain h1/p/ul/pre/table/img), none of Crepe's
     * node views or controls. See styles/print.css.
     */
    #mountPrintCopy(): void {
        const editor = this.#crepe?.editor;
        if (!editor || editor.status !== EditorStatus.Created) {
            return;
        }

        this.#removePrintCopy();

        const { state } = editor.ctx.get(editorViewCtx);
        const copy = document.createElement('div');
        copy.className = 'print-copy document';
        copy.append(DOMSerializer.fromSchema(state.schema).serializeFragment(state.doc.content));
        document.body.append(copy);
        this.#printCopy = copy;
    }

    #removePrintCopy(): void {
        this.#printCopy?.remove();
        this.#printCopy = null;
    }

    /**
     * Picked paths are always absolute and never rewritten to relative,
     * whether the document is new or already open — see EDITOR_IMAGES.md.
     */
    async #insertImageFromPicker(ctx: Ctx): Promise<void> {
        const path = await pickPath('file');
        if (path === null) {
            return;
        }

        const commands = ctx.get(commandsCtx);
        const imageBlock = imageBlockSchema.type(ctx);
        commands.call(clearTextInCurrentBlockCommand.key);
        commands.call(addBlockTypeCommand.key, {
            nodeType: imageBlock,
            attrs: { src: `${this.urlsValue.image}?path=${encodeURIComponent(path)}` },
        });
    }

    /**
     * Save follows the dirty state, not the presence of text (FIL-07, lot 03):
     * active as soon as the document differs from what was saved — an emptied
     * file is a legitimate zero-byte file — but only once it has a path to
     * save to; without one, Save as is the CTA and Save stays grayed. Save as
     * stays open even on a clean or empty document. Both gray out while
     * readonly, while a save is in flight, and from a load until its outcome
     * (FRT-01, FRT-04).
     */
    #updateSaveButton(markdown?: string): void {
        const busy = this.#inFlightSave !== null || this.#loadingFile || this.#loadFailed;
        if (this.hasSaveButtonTarget) {
            this.saveButtonTarget.disabled = this.#isReadonly || busy || this.#currentPath === null || !this.#isDirty(markdown);
        }
        if (this.hasSaveAsButtonTarget) {
            this.saveAsButtonTarget.disabled = this.#isReadonly || busy;
        }
    }

    #updatePrintButton(markdown: string): void {
        if (this.hasPrintButtonTarget) {
            this.printButtonTarget.disabled = markdown.trim() === '';
        }
    }

    #updateCopyMarkdownButton(markdown: string): void {
        if (this.hasCopyMarkdownButtonTarget) {
            this.copyMarkdownButtonTarget.disabled = markdown.trim() === '';
        }
    }

    #updateDirtyIndicator(markdown?: string): void {
        if (!this.hasDirtyIndicatorTarget) {
            return;
        }
        this.dirtyIndicatorTarget.hidden = !this.#isDirty(markdown);
    }

    #isDirty(markdown?: string): boolean {
        const current = markdown ?? this.#crepe?.getMarkdown() ?? '';

        return current !== this.#savedRef;
    }

    #updateFilePath(): void {
        if (!this.hasFilePathTarget) {
            return;
        }
        const label = this.#currentPath ?? this.i18nValue.untitled ?? 'Untitled';
        this.filePathTarget.textContent = label;
        this.filePathTarget.title = label;
    }

    #restoreFocus(): void {
        this.element.querySelector<HTMLElement>('.ProseMirror')?.focus();
    }

    #isAiInProgress(): boolean {
        const editor = this.#crepe?.editor;
        if (!editor || editor.status !== EditorStatus.Created) {
            return false;
        }
        const view = editor.ctx.get(editorViewCtx);
        const streaming = streamingPluginKey.getState(view.state);
        const diff = diffPluginKey.getState(view.state);
        if ((streaming?.active ?? false) || (diff?.active ?? false)) {
            return true;
        }

        // The Crepe session (instruct/abort), read in test mode: without a
        // dispatch, the command only says whether it would run (IA-04, lot 03).
        const commands = editor.ctx.get(commandsCtx);
        const sessionActive = commands.get(abortAICmd.key)({ keep: false })(view.state);

        return sessionActive;
    }

    #shouldConfirmLeave(): boolean {
        return this.#isDirty() || this.#isAiInProgress();
    }

    /**
     * Guards clicks on elements marked data-editor-leave-guard, links and JS
     * actions alike. The click is stopped before the element's own handlers or
     * navigation; once confirmed, any AI generation or review is discarded and
     * the click is replayed, skipping the guard.
     */
    #guardLeave(event: MouseEvent): void {
        const guarded = (event.target as Element | null)?.closest<HTMLElement>('[data-editor-leave-guard]');
        if (!guarded || this.#leaveConfirmed || !this.#shouldConfirmLeave()) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        void confirmDialog({
            question: this.i18nValue.unsaved?.confirm ?? 'Continue and lose unsaved changes?',
            cancelLabel: this.i18nValue.unsaved?.cancel ?? 'Cancel',
            continueLabel: this.i18nValue.unsaved?.continue ?? 'Continue',
        }).then((confirmed) => {
            if (!confirmed) {
                this.#restoreFocus();

                return;
            }

            this.#discardAi();
            // click() dispatches synchronously, so the flag only covers the replay.
            this.#leaveConfirmed = true;
            try {
                guarded.click();
            } finally {
                this.#leaveConfirmed = false;
            }
        });
    }

    // Aborting ends the provider's generator, whose finally tells the worker to stop.
    #discardAi(): void {
        if (!this.#aiEnabled) {
            return;
        }

        const editor = this.#crepe?.editor;
        if (!editor || editor.status !== EditorStatus.Created) {
            return;
        }

        editor.action((ctx) => {
            const commands = ctx.get(commandsCtx);
            commands.call(abortAICmd.key, { keep: false });
            if (diffPluginKey.getState(ctx.get(editorViewCtx).state)?.active) {
                commands.call(clearDiffReviewCmd.key);
            }
        });
    }

}
