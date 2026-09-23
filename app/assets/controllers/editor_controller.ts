import { Controller } from '@hotwired/stimulus';
import AiClient from '../editor/ai-client';
import CrepeHost, { type CrepeI18n, type ImageOrigin } from '../editor/crepe-host';
import { type Draft, clearDraft, readDraft, writeDraft } from '../editor/draft';
import { type EditorState, emit, on } from '../editor/events';
import { basename } from '../editor/file-entries';
import LeaveGuard from '../editor/leave-guard';
import PrintCopy from '../editor/print-copy';
import EditorShortcuts from '../editor/shortcuts';
import { copyToClipboard } from '../utils/copy-to-clipboard';
import { saveConflictDialog } from '../utils/conflict-dialog';
import { draftConflictDialog } from '../utils/draft-conflict-dialog';
import { request } from '../utils/http';
import { type IpcI18n, pickPath, savePath } from '../utils/tauri';
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

/**
 * The whole of Editor::getI18n(): the controller's own texts, plus what
 * `CrepeI18n` passes straight through to CrepeHost (placeholder, link,
 * slashMenu, codeBlock, ai.*) and `ai.requestFailed`, for AiClient. No key is
 * optional: the server always sends the lot, so a front-end fallback would
 * only ever mask a translation missing at the source.
 */
export interface I18n extends CrepeI18n {
    toggle: { edit: string; readonly: string };
    untitled: string;
    unsaved: { confirm: string; cancel: string; continue: string };
    conflict: { question: string; cancel: string; saveAs: string; overwrite: string };
    loadError: string;
    /** FRT-11, lot 08: the editor could not be created at all. */
    initFailed: string;
    ipc: IpcI18n;
    /** FRT-08, lot 08: the picked file is not an image the app can serve. */
    imageInvalid: string;
    toast: {
        saved: string;
        savedAs: string;
        copiedMarkdown: string;
        copyMarkdownFailed: string;
        copiedCode: string;
        copyCodeFailed: string;
        currentFileDeleted: string;
        currentFileGone: string;
        fileNotFound: string;
        draftRestored: string;
    };
    draftConflict: { question: string; keepDraft: string; useDisk: string };
    ai: CrepeI18n['ai'] & { requestFailed: string };
}

/**
 * GET /editor/file: the current file with its revision, none, or the
 * state's refusal form (`genericErrors`) on a failed read.
 */
export interface FileResponse {
    path?: string | null;
    content?: string | null;
    revision?: string | null;
    genericErrors?: string[];
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
        urls: Object,
        aiConfig: { type: Object, default: {} },
        readonly: { type: Boolean, default: false },
        // Defaults for the editor's own texts (placeholder, slash menu, AI panel)
        // live in CrepeHost; only controller-owned UI text falls back here.
        i18n: Object,
    };

    static targets = ['saveButton', 'saveAsButton', 'newButton', 'printButton', 'copyMarkdownButton', 'toggleLabel', 'dirtyIndicator', 'filePath', 'loadErrorMessage'];

    static outlets = ['editor-state'];

    declare readonly editorStateOutlet: EditorStateController;

    declare readonly urlsValue: Urls;
    declare readonly aiConfigValue: AiConfig;
    declare readonly readonlyValue: boolean;
    declare readonly i18nValue: I18n;

    declare readonly hasSaveButtonTarget: boolean;
    declare readonly saveButtonTarget: HTMLButtonElement;
    declare readonly hasSaveAsButtonTarget: boolean;
    declare readonly saveAsButtonTarget: HTMLButtonElement;
    declare readonly hasNewButtonTarget: boolean;
    declare readonly newButtonTarget: HTMLButtonElement;
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

    #host!: CrepeHost;
    // The outlet can connect before connect() has even started (Stimulus
    // starts the outlet observer first): it waits on this.
    #crepeReady!: Promise<void>;
    #resolveCrepe!: () => void;
    // Crepe is created once the state is known: it says whether the AI is on.
    #stateReady!: Promise<EditorState>;
    #resolveState!: (state: EditorState) => void;
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
    // Read once, in initialize() — before #reset() or anything else can touch
    // sessionStorage — and consumed at most once, by whichever of startup's
    // paths turns out to match its path: the load that follows, or directly
    // here for an untitled one (lot 04-brouillon.md). Never read again after.
    #pendingDraft: Draft | null = null;
    // From the moment the editor empties for a load until the file lands:
    // the document is not editable meanwhile (FRT-04).
    #loadingFile = false;
    // The load failed for something else than an abort: the explicit error
    // state, with Retry (FRT-04).
    #loadFailed = false;
    #isReadonly = false;
    #aiClient: AiClient | null = null;
    #fileRequest: AbortController | null = null;
    #unsubscribers: Array<() => void> = [];
    #guard!: LeaveGuard;
    #print!: PrintCopy;
    #shortcuts!: EditorShortcuts;
    // Bumped by disconnect(): a connect() that was still waiting on an await
    // when the teardown came stops there, and poses no listener (FRT-11).
    #generation = 0;

    initialize(): void {
        this.#pendingDraft = readDraft();
        this.#crepeReady = new Promise((resolve) => (this.#resolveCrepe = resolve));
        this.#stateReady = new Promise((resolve) => (this.#resolveState = resolve));
        // Built here, not in connect(): connect() waits for the state before
        // creating Crepe, and the element can be gone by then — disconnect()
        // must still have a host to tear down.
        this.#host = new CrepeHost(this.element, this.i18nValue, {
            onInsertImage: (origin) => void this.#insertImageFromPicker(origin),
            onCopyCode: (text) => void this.#copyCode(text),
            // Crepe prefixes the message ("AI provider error: ..."); show the original one.
            onAiError: (error) => showToast('error', (error.cause as Error | undefined)?.message ?? error.message),
        });
        this.#host.onChange((markdown) => {
            this.#updateSaveButton(markdown);
            this.#updatePrintButton(markdown);
            this.#updateCopyMarkdownButton(markdown);
            this.#updateDirtyIndicator(markdown);
            this.#syncDraft(markdown);
        });
        this.#guard = new LeaveGuard(this.i18nValue.unsaved, {
            shouldConfirm: () => this.#shouldConfirmLeave(),
            onLeave: () => this.#host.discardAi(),
            onStay: () => this.#host.focus(),
        });
        this.#print = new PrintCopy(() => this.#host.printCopy());
        // FRT-10 + UX-03, lot 08: the buttons do the work — disabled and
        // leave guard included.
        this.#shortcuts = new EditorShortcuts({
            save: () => (this.hasSaveButtonTarget ? this.saveButtonTarget : null),
            newFile: () => (this.hasNewButtonTarget ? this.newButtonTarget : null),
        });
    }

    connect(): void {
        void this.#connect();
    }

    /**
     * FRT-11, lot 08: Stimulus never awaits connect(), so a rejection of the
     * state or of Crepe's creation is caught here — the editor shows the
     * error toast instead of staying empty without a word. And after each
     * await, a teardown that came first stops the whole thing: no listener,
     * no guard, nothing is posed on a controller that is already gone.
     */
    async #connect(): Promise<void> {
        const generation = this.#generation;
        this.#isReadonly = this.readonlyValue;

        try {
            const { ai_enabled: aiEnabled } = await this.#stateReady;
            if (this.#generation !== generation) {
                return;
            }
            this.#connectAiClient(aiEnabled);

            await this.#host.create({ aiEnabled, aiProvider: this.#aiClient?.createProvider() });
            if (this.#generation !== generation) {
                return;
            }

            // A new document is clean: capture the empty editor's markdown as the
            // reference so the indicator doesn't fire on the initial content.
            this.#savedRef = this.#host.markdown();

            this.#print.listen();
            // Capture phase, on window: covers the sidebar (mode-single / mode-dir),
            // not just this element, since navigation there also drops unsaved work.
            this.#guard.listen();
            this.#shortcuts.listen();
            this.#listen();

            if (this.#isReadonly) {
                this.#applyReadonlyState();
            }

            this.#updateSaveButton('');
            this.#updatePrintButton('');
            this.#updateCopyMarkdownButton('');
            this.#updateDirtyIndicator(this.#savedRef);
            this.#updateFilePath();

            this.#resolveCrepe();
        } catch (error) {
            console.error('The editor could not be created:', error);
            showToast('error', this.i18nValue.initFailed);
        }
    }

    disconnect(): void {
        this.#generation++;
        this.#print.stop();
        this.#guard.stop();
        this.#shortcuts.stop();
        this.#unsubscribers.forEach((unsubscribe) => unsubscribe());
        this.#unsubscribers = [];
        this.#fileRequest?.abort();
        this.#aiClient?.close();
        this.#host.destroy();
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

        // A draft for another path (or an untitled one against a current
        // file, or the reverse) isn't this document's: abandoned at once
        // rather than kept for a load it doesn't belong to (lot 04-brouillon.md).
        if (this.#pendingDraft !== null && this.#pendingDraft.path !== state.file) {
            clearDraft();
            this.#pendingDraft = null;
        }

        if (state.file !== null) {
            this.#beginLoad();
        } else if (this.#pendingDraft !== null) {
            const draft = this.#pendingDraft;
            this.#pendingDraft = null;
            this.#restoreUntitledDraft(draft.markdown);
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
            // was an intention to leave, leave guard included. A failure can
            // also carry the file a request abandoned before it applied
            // (FRT-03, lot 08): the state is the truth, the editor follows.
            on('editor:nav-change_file-failed', ({ state, action }) => {
                if (action.path === this.#currentPath && state.file === null) {
                    this.#currentFileGone(this.#fileGoneMessage(this.i18nValue.toast.currentFileGone, action.path));
                } else if (state.file === null && this.#currentPath !== null) {
                    this.#resetTo(state);
                } else if (state.file !== null && state.file !== this.#currentPath) {
                    this.#syncDirectory(state);
                    this.#beginLoad();
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

            on('editor:do-save-succeeded', ({ action }) => this.#saved(this.i18nValue.toast.saved, action.revision)),
            on('editor:do-save-failed', ({ action }) => this.#saveFailed(action.status, action.message ?? null)),
            on('editor:do-save_as-succeeded', ({ state, action }) => {
                // The server made the new file the current one, realpath'd.
                this.#currentPath = action.path;
                this.#syncDirectory(state);
                this.#updateFilePath();
                const template = this.i18nValue.toast.savedAs;
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
                    this.#currentFileGone(this.#fileGoneMessage(this.i18nValue.toast.currentFileDeleted, action.path));
                }
            }),
            on('editor:do-rename-succeeded', ({ action }) => {
                if (action.oldPath !== this.#currentPath) {
                    return;
                }
                this.#currentPath = action.newPath;
                this.#updateSaveButton(this.#host.markdown());
                this.#syncDraft();
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
                    this.#currentFileGone(this.#fileGoneMessage(this.i18nValue.toast.currentFileGone, anomaly.file));
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

    /** The AI client, once the hub and topic are known: only with AI on. */
    #connectAiClient(aiEnabled: boolean): void {
        // A client is kept only while the AI is on: the caller has closed the
        // previous one, and nothing must be able to reach it afterwards.
        this.#aiClient = null;
        const { mercureUrl, topic } = this.aiConfigValue;
        if (!aiEnabled || mercureUrl === undefined || topic === undefined) {
            return;
        }

        this.#aiClient = new AiClient({
            urls: { subscribe: this.urlsValue.aiSubscribe, instruct: this.urlsValue.aiInstruct, abort: this.urlsValue.aiAbort },
            mercureUrl,
            topic,
            requestFailedMessage: this.i18nValue.ai.requestFailed,
        });
    }

    /**
     * If the state's `ai_enabled` is no longer what Crepe was created with,
     * recreates it around the same markdown. Nothing is lost, so no leave
     * guard. The file, the read-only mode and the "unsaved" state stay; the
     * selection and the focus come back (FRT-05, lot 08); the undo history
     * starts over, as when a file is opened.
     */
    async #followAi(state: EditorState): Promise<void> {
        const markdown = await this.#host.recreate(state.ai_enabled, () => {
            // The order of today, kept: the old client closes only once Crepe
            // (and the generation it might be running) is gone.
            this.#aiClient?.close();
            this.#connectAiClient(state.ai_enabled);

            return this.#aiClient?.createProvider();
        });
        if (markdown === null) {
            return;
        }

        // A clean document stays clean even if Crepe serializes it a little
        // differently the second time.
        if (!this.#isDirty(markdown)) {
            this.#savedRef = this.#host.markdown();
        }
        if (this.#isReadonly) {
            this.#applyReadonlyState();
        }
        this.#updateSaveButton(this.#host.markdown());
        this.#updatePrintButton(this.#host.markdown());
        this.#updateCopyMarkdownButton(this.#host.markdown());
        this.#updateDirtyIndicator();
        // onChange already ran #syncDraft() once, against #savedRef as it
        // stood before the adjustment above: redone here against the
        // corrected one, or a clean document could be left with a stale
        // draft (lot 04-brouillon.md).
        this.#syncDraft();
    }

    toggleReadonly(): void {
        this.#isReadonly = !this.#isReadonly;
        this.#applyReadonlyState();
    }

    #applyReadonlyState(): void {
        this.#applyEditable();
        this.#updateSaveButton(this.#host.markdown());

        if (this.hasToggleLabelTarget) {
            this.toggleLabelTarget.textContent = this.#isReadonly
                ? this.i18nValue.toggle.edit
                : this.i18nValue.toggle.readonly;
        }
    }

    /**
     * The document is editable only in edit mode, and only once what it shows
     * is really there: not while the file loads (the user already chose to
     * leave the previous one), not when its load failed (FRT-04, lot 03).
     */
    #applyEditable(): void {
        const editable = !this.#isReadonly && !this.#loadingFile && !this.#loadFailed;
        this.#host.setEditable(editable);
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
        this.#reset(true);
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
        const fileRequest = new AbortController();
        this.#fileRequest = fileRequest;
        // The path this read is for: the master's state already points at
        // it (the events that lead here fire after the master merges), and
        // a 404 no longer echoes it back.
        const loadingPath = this.editorStateOutlet.state.file;

        try {
            const result = await request<FileResponse>(this.urlsValue.file, { signal: fileRequest.signal });

            if (result.status === 404) {
                this.#reset(true);
                if (loadingPath !== null) {
                    emit('editor:state-anomaly-reported', { anomaly: { file: loadingPath } });
                }

                // A draft for this now-gone path returns as an untitled
                // document, same as any other anomaly (lot 04-brouillon.md);
                // otherwise the usual "not found" toast.
                const draft = this.#pendingDraft;
                this.#pendingDraft = null;
                if (draft !== null && draft.path === loadingPath) {
                    this.#restoreUntitledDraft(draft.markdown);
                } else {
                    showToast('error', result.data?.genericErrors?.[0] || this.i18nValue.toast.fileNotFound);
                }

                return;
            }

            if (!result.ok) {
                this.#failLoad(result.data?.genericErrors?.[0] ?? null);

                return;
            }

            // Crepe may be mid-recreation: the file lands in the new one.
            await this.#host.whenIdle();
            if (fileRequest.signal.aborted) {
                return;
            }

            if (result.data?.path === null || result.data?.path === undefined) {
                this.#reset();
            } else {
                this.#applyLoadedFile(result.data.path, result.data.content ?? '', result.data.revision ?? null);
            }
        } catch (err) {
            if (err instanceof DOMException && err.name === 'AbortError') {
                return;
            }
            console.error('Failed to open file:', err);
            this.#failLoad((err as Error).message || null);
        } finally {
            if (this.#fileRequest === fileRequest) {
                this.#fileRequest = null;
            }
        }
    }

    #applyLoadedFile(path: string, content: string, revision: string | null): void {
        this.#loadingFile = false;
        this.#loadFailed = false;
        this.element.classList.remove('is-load-failed');
        this.#currentPath = path;
        this.#revision = revision;
        this.#host.replace(content);
        this.#savedRef = this.#host.markdown();
        this.#applyEditable();
        this.#updateSaveButton(this.#savedRef);
        // Loading is not a change: Milkdown's listener re-bases on the new
        // document, so onChange doesn't fire and these two must be set here.
        this.#updatePrintButton(this.#savedRef);
        this.#updateCopyMarkdownButton(this.#savedRef);
        this.#updateDirtyIndicator(this.#savedRef);
        this.#updateFilePath();

        // A draft for exactly this file, read at startup (lot 04-brouillon.md).
        // It stays pending, and the stored copy untouched, until
        // #restoreDraft() has settled: a reload while its dialog is open
        // must still find it.
        const draft = this.#pendingDraft;
        if (draft !== null && draft.path === path) {
            void this.#restoreDraft(draft);
        } else {
            this.#pendingDraft = null;
            this.#syncDraft(this.#savedRef);
        }
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
     *
     * Every way out of the document drops the draft read at startup (lot
     * 04-brouillon.md) — leaving is an intention — except the load of the
     * file it may belong to ($keepPendingDraft), which it waits for.
     */
    #reset(keepPendingDraft = false): void {
        if (!keepPendingDraft) {
            this.#pendingDraft = null;
        }
        // Last action wins: a file still loading must not replace the new one.
        this.#fileRequest?.abort();
        this.#loadingFile = false;
        this.#loadFailed = false;
        this.element.classList.remove('is-load-failed');
        this.#revision = null;
        if (!this.#host.created) {
            return;
        }
        this.#host.replace('');
        this.#currentPath = null;
        this.#savedRef = this.#host.markdown();
        this.#applyEditable();
        this.#updateSaveButton(this.#savedRef);
        this.#updatePrintButton(this.#savedRef);
        this.#updateCopyMarkdownButton(this.#savedRef);
        this.#updateDirtyIndicator(this.#savedRef);
        this.#syncDraft(this.#savedRef);
        this.#updateFilePath();
    }

    saveFile(): void {
        // Without a path there is nothing to save to: Save as is the CTA for
        // that, and the button is grayed (lot 03, reversed on Arnaud's
        // feedback: two CTAs doing the same thing only confuse).
        if (this.#isReadonly || this.#inFlightSave !== null || !this.#host.created || this.#currentPath === null) {
            return;
        }

        const markdown = this.#host.markdown();
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
        // The button stays down from the picker to the answer: whatever
        // happens to the invoke, it comes back (FRT-07, lot 08).
        if (this.hasSaveAsButtonTarget) {
            this.saveAsButtonTarget.disabled = true;
        }
        let path: string | null;
        try {
            // In dir mode, the save dialog opens in the current directory without
            // constraining where the file actually gets saved (see EDITOR_FOLDER_MODE.md).
            path = await savePath(this.i18nValue.ipc, defaultName, this.#directory, undefined);
        } finally {
            this.#updateSaveButton(this.#host.markdown());
        }
        // The picker can outlast a change of mind: everything is re-read after.
        if (path === null || !this.#host.created || this.#isReadonly || this.#inFlightSave !== null) {
            return;
        }

        this.#beginSave('do-save_as', path, this.#host.markdown(), null);
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
        this.#updateSaveButton(this.#host.markdown());
        this.#updateDirtyIndicator();
        this.#syncDraft();
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
        this.#updateSaveButton(this.#host.markdown());

        if (status !== 409 || save === null) {
            return;
        }

        void saveConflictDialog({
            message,
            question: this.i18nValue.conflict.question,
            cancelLabel: this.i18nValue.conflict.cancel,
            saveAsLabel: this.i18nValue.conflict.saveAs,
            overwriteLabel: this.i18nValue.conflict.overwrite,
        }).then((choice) => {
            if (choice === 'save_as') {
                void this.saveFileAs();
            } else if (choice === 'overwrite') {
                this.#beginSave('do-save', save.path, this.#host.markdown(), null);
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
            this.loadErrorMessageTarget.textContent = message ?? this.i18nValue.loadError;
        }
        this.#updateSaveButton('');
        this.#applyEditable();
    }

    /**
     * Anomaly (lot 03): the current file is gone or was deleted, but nobody
     * asked for the text to go. It stays, the path falls, the document
     * becomes an untitled one, and the toast says how to keep it.
     *
     * Untitled *and unsaved*: its copy on disk is gone, so what is on screen
     * exists nowhere else. The saved reference goes back to empty, which makes
     * the dot show and the leave guard ask — an empty document stays clean,
     * there is nothing to lose.
     */
    #currentFileGone(message: string): void {
        this.#host.discardAi();
        this.#currentPath = null;
        this.#revision = null;
        this.#savedRef = '';
        this.#updateSaveButton(this.#host.markdown());
        this.#updateDirtyIndicator();
        this.#syncDraft();
        this.#updateFilePath();
        showToast('error', message);
    }

    #fileGoneMessage(template: string, path: string): string {
        return template.replace('{name}', basename(path));
    }

    /**
     * The single rule of lot 04-brouillon.md: a modified document keeps a
     * draft, a clean one doesn't. Called from onChange and from every other
     * place the modified state changes, or the identity (path, revision) it
     * would be written under does.
     */
    #syncDraft(markdown?: string): void {
        // The draft read at startup owns the stored copy until it is
        // restored or abandoned: #reset() and the load before it would
        // otherwise clear it, and a reload in between would lose it.
        if (this.#pendingDraft !== null) {
            return;
        }
        const current = markdown ?? this.#host.markdown();
        if (this.#isDirty(current)) {
            writeDraft({ path: this.#currentPath, markdown: current, revision: this.#revision });
        } else {
            clearDraft();
        }
    }

    /**
     * A draft for the file just loaded (lot 04-brouillon.md). The same
     * revision restores at once; a different one — the file changed on disk
     * since — asks before the user resumes editing. Keeping the draft adopts
     * the disk's revision, so the next Save has nothing to conflict with;
     * using the disk drops it, back to the clean document just loaded. The
     * stored draft is only rewritten or cleared once the choice is made.
     */
    async #restoreDraft(draft: Draft): Promise<void> {
        if (draft.revision === this.#revision) {
            this.#pendingDraft = null;
            this.#applyDraftText(draft.markdown);
            showToast('success', this.i18nValue.toast.draftRestored);

            return;
        }

        const choice = await draftConflictDialog({
            question: this.i18nValue.draftConflict.question,
            keepDraftLabel: this.i18nValue.draftConflict.keepDraft,
            useDiskLabel: this.i18nValue.draftConflict.useDisk,
        });

        this.#pendingDraft = null;
        if (choice === 'keep_draft') {
            this.#applyDraftText(draft.markdown);
        } else {
            clearDraft();
        }
    }

    /**
     * A draft with no path, or one whose file turned out gone: comes back as
     * an untitled document, unsaved — same shape as the anomaly of lot 03
     * (#currentFileGone), the copy on screen is the only one left.
     */
    #restoreUntitledDraft(markdown: string): void {
        this.#currentPath = null;
        this.#revision = null;
        this.#savedRef = '';
        this.#applyDraftText(markdown);
        this.#updateFilePath();
        showToast('success', this.i18nValue.toast.draftRestored);
    }

    #applyDraftText(markdown: string): void {
        this.#host.replace(markdown);
        this.#applyEditable();
        this.#updateSaveButton(markdown);
        this.#updatePrintButton(markdown);
        this.#updateCopyMarkdownButton(markdown);
        this.#updateDirtyIndicator(markdown);
        this.#syncDraft(markdown);
    }

    printFile(): void {
        this.#print.print();
    }

    async copyMarkdown(): Promise<void> {
        const markdown = this.#host.markdown();
        await copyToClipboard(this.#convertImageUrlsForCopy(markdown), {
            success: this.i18nValue.toast.copiedMarkdown,
            failure: this.i18nValue.toast.copyMarkdownFailed,
        });
    }

    async #copyCode(text: string): Promise<void> {
        await copyToClipboard(text, {
            success: this.i18nValue.toast.copiedCode,
            failure: this.i18nValue.toast.copyCodeFailed,
        });
    }

    /**
     * The editor only ever holds /document/image service URLs for local images
     * (see EDITOR_IMAGES.md); the server converts them back to their raw
     * path before the markdown leaves the editor, same as save().
     */
    async #convertImageUrlsForCopy(markdown: string): Promise<string> {
        const result = await request<{ content: string }>(this.urlsValue.copy, { method: 'POST', body: { content: markdown } });

        if (!result.ok) {
            throw new Error(`Copy failed: ${result.status}`);
        }

        return result.data!.content;
    }

    /**
     * Picked paths are always absolute and never rewritten to relative,
     * whether the document is new or already open — see EDITOR_IMAGES.md.
     *
     * FRT-02, lot 08: the two origins do not insert the same way — the
     * slash menu clears its `/image` command block, the top bar adds the
     * node at the selection and lets the text be. FRT-08, lot 08: the
     * service URL is checked first; a 404 (session, path, type, size — the
     * picker filters nothing) or a network error inserts nothing, the
     * selection is kept and a toast says why.
     */
    async #insertImageFromPicker(origin: ImageOrigin): Promise<void> {
        const path = await pickPath('file', this.i18nValue.ipc);
        if (path === null) {
            return;
        }

        const src = `${this.urlsValue.image}?path=${encodeURIComponent(path)}`;
        if (!(await this.#imageUsable(src))) {
            showToast('error', this.i18nValue.imageInvalid);

            return;
        }

        if (origin === 'slash-menu') {
            this.#host.insertImageFromSlashMenu(src);

            return;
        }
        this.#host.insertImage(src);
    }

    /**
     * A HEAD against the image route: 2xx says the server can serve it, 404
     * says it refuses (the route's answer for every refusal), a network
     * error says the answer never came. None of the body is downloaded.
     */
    async #imageUsable(src: string): Promise<boolean> {
        try {
            const response = await fetch(src, { method: 'HEAD' });

            return response.ok;
        } catch (error) {
            console.error('The image could not be checked:', error);

            return false;
        }
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
        const current = markdown ?? this.#host.markdown();

        return current !== this.#savedRef;
    }

    #updateFilePath(): void {
        if (!this.hasFilePathTarget) {
            return;
        }
        const label = this.#currentPath ?? this.i18nValue.untitled;
        this.filePathTarget.textContent = label;
        this.filePathTarget.title = label;
    }

    #shouldConfirmLeave(): boolean {
        return this.#isDirty() || this.#host.isAiBusy();
    }

}
