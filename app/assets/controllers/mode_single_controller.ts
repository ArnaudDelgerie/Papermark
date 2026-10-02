import { Controller } from '@hotwired/stimulus';
import { onCurrentFile } from '../editor/current-file';
import { type EditorState, on } from '../editor/events';
import { FileEntries, type FileEntryI18n, basename, entryPath } from '../editor/file-entries';
import { onModeShown } from '../editor/mode-shown';
import { markCurrentFile } from '../utils/mark-current';
import { DOCUMENT_EXTENSIONS } from '../utils/extensions';
import { type IpcI18n, pickPath } from '../utils/tauri';
import type EditorStateController from './editor_state_controller';

const STORAGE_KEY = 'editor.single.history';

/** The column's own texts, beyond what its file entries show. */
export interface ModeSingleI18n extends FileEntryI18n {
    ipc: IpcI18n;
    /** The Open picker's documents filter name, translated. */
    documentFilter: string;
}

/**
 * History of files opened in single mode: sessionStorage only (lost on app
 * restart, accepted — see EDITOR_FOLDER_MODE.md). An entry goes on top when a
 * server answer makes a file the current one in single mode, whatever the
 * action that led there — a picker, a history click, a link followed, an
 * import, an open_path, a Save as. Nothing is added at click time, so a
 * refused open never entered and needs no cleanup. Delete and rename are
 * followed whoever acted, and so is a current file found gone (the anomaly).
 *
 * UX-09, lot 10: the entry of the file the editor shows carries
 * `aria-current` and the active style, read from the master's outlet at
 * start and from the events after.
 */
export default class extends Controller<HTMLElement> {
    static values = { maxEntries: { type: Number, default: 50 }, i18n: Object };
    static targets = ['openButton', 'list', 'empty', 'loading'];
    static outlets = ['editor-state'];

    declare readonly maxEntriesValue: number;
    declare readonly i18nValue: ModeSingleI18n;
    declare readonly openButtonTarget: HTMLButtonElement;
    declare readonly listTarget: HTMLElement;
    declare readonly emptyTarget: HTMLElement;
    declare readonly loadingTarget: HTMLElement;
    declare readonly hasEditorStateOutlet: boolean;
    declare readonly editorStateOutlet: EditorStateController;

    #entries = new FileEntries(() => this.i18nValue);
    #unsubscribers: Array<() => void> = [];
    #currentFile: string | null = null;

    connect(): void {
        this.#entries.connect();
        if (this.hasEditorStateOutlet) {
            this.#currentFile = this.editorStateOutlet.state.file;
        }
        // A server answer that made a file the current one in single mode:
        // the real path (realpath'd by the server), whatever the action that
        // led there. A file opened in dir mode never enters.
        const pushOpenedFile = (state: EditorState): void => {
            if (state.mode === 'single' && state.file !== null) {
                this.#push(state.file);
            }
        };
        this.#unsubscribers = [
            onModeShown((mode) => {
                this.element.hidden = mode !== 'single';
            }),
            onCurrentFile((file) => {
                this.#currentFile = file;
                this.#mark();
            }),
            // Every open, whatever asked for it — the picker, a history
            // entry, a link followed in the document (EDITOR_LINKS.md).
            on('editor:nav-change_file-succeeded', ({ state }) => pushOpenedFile(state)),
            // The path "Save as" just created and made current: its own
            // event, the same entry rule as any open (EDITOR_FIX.md #5).
            on('editor:do-save_as-succeeded', ({ state }) => pushOpenedFile(state)),
            // The file an archive or an open_path opened: `openMode` says it,
            // not the state — an import that opened nothing leaves the
            // current file where it was, without re-entering it.
            on('editor:do-import-succeeded', ({ state, action }) => {
                if (action.openMode === 'single') {
                    pushOpenedFile(state);
                }
            }),
            on('editor:nav-open_path-succeeded', ({ state, action }) => {
                if (action.openMode === 'single') {
                    pushOpenedFile(state);
                }
            }),
            on('editor:do-delete-succeeded', ({ action }) => this.#remove(action.path)),
            // In place: renaming isn't a re-open, so it doesn't reorder.
            on('editor:do-rename-succeeded', ({ action }) => {
                this.#write(this.#read().map((entry) => (entry === action.oldPath ? action.newPath : entry)));
            }),
            on('editor:state-resynced', ({ anomaly }) => {
                if (anomaly.file !== undefined) {
                    this.#remove(anomaly.file);
                }
            }),
        ];
        this.#render();
    }

    disconnect(): void {
        this.#entries.disconnect();
        this.#unsubscribers.forEach((unsubscribe) => unsubscribe());
        this.#unsubscribers = [];
    }

    async openFile(): Promise<void> {
        // The button stays down until the picker answers, whatever the
        // invoke does with it (FRT-07, lot 08).
        this.openButtonTarget.disabled = true;
        let path: string | null;
        try {
            path = await pickPath('file', this.i18nValue.ipc, [
                { name: this.i18nValue.documentFilter, extensions: DOCUMENT_EXTENSIONS },
            ]);
        } finally {
            this.openButtonTarget.disabled = false;
        }
        if (path === null) {
            return;
        }

        // No #push: the entry goes in when the server answers (see the
        // class docblock), so a refused open never entered.
        this.#entries.open(path);
    }

    openHistoryEntry(event: Event): void {
        event.preventDefault();
        const path = entryPath(event);
        this.#entries.open(path);
    }

    async deleteEntry(event: Event): Promise<void> {
        event.preventDefault();
        await this.#entries.delete(entryPath(event));
    }

    async renameEntry(event: Event): Promise<void> {
        event.preventDefault();
        await this.#entries.rename(entryPath(event));
    }

    #push(path: string): void {
        const history = this.#read().filter((entry) => entry !== path);
        history.unshift(path);
        this.#write(history.slice(0, this.maxEntriesValue));
    }

    #remove(path: string): void {
        this.#write(this.#read().filter((entry) => entry !== path));
    }

    #read(): string[] {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY);

            return raw ? JSON.parse(raw) : [];
        } catch {
            return [];
        }
    }

    #write(history: string[]): void {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history));
        } catch {
            // sessionStorage unavailable (private mode, quota…): history just won't persist.
        }
        this.#render();
    }

    #render(): void {
        const history = this.#read();

        this.listTarget.replaceChildren(...history.map((path) => this.#entry(path)));
        this.loadingTarget.hidden = true;
        this.listTarget.hidden = history.length === 0;
        this.emptyTarget.hidden = history.length > 0;
        this.#mark();
    }

    #mark(): void {
        markCurrentFile(this.listTarget, this.#currentFile);
    }

    #entry(path: string): HTMLLIElement {
        const li = document.createElement('li');
        const link = document.createElement('a');
        link.href = '#';
        link.title = path;
        link.dataset.path = path;
        link.dataset.action = 'click->mode-single#openHistoryEntry';
        link.setAttribute('data-editor-leave-guard', '');

        const label = document.createElement('span');
        label.className = 'mode-tree-label';
        label.textContent = basename(path);
        link.append(label);

        const actions = document.createElement('span');
        actions.className = 'mode-entry-actions';
        actions.append(
            this.#actionButton(path, 'rename', this.i18nValue.rename, 'renameEntry'),
            this.#actionButton(path, 'delete', this.i18nValue.delete, 'deleteEntry'),
        );

        li.append(link, actions);

        return li;
    }

    #actionButton(path: string, kind: string, title: string, method: string): HTMLButtonElement {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `mode-entry-action mode-entry-action--${kind}`;
        button.title = title;
        button.dataset.path = path;
        button.dataset.action = `click->mode-single#${method}`;

        return button;
    }
}
