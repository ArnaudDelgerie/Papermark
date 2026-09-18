import { Controller } from '@hotwired/stimulus';
import { on } from '../editor/events';
import { FileEntries, type FileEntryI18n, basename, entryPath } from '../editor/file-entries';
import { onModeShown } from '../editor/mode-shown';
import { pickPath } from '../utils/tauri';

const STORAGE_KEY = 'editor.single.history';

/**
 * History of files opened in single mode: sessionStorage only (lost on app
 * restart, accepted — see EDITOR_FOLDER_MODE.md). An entry goes on top as
 * soon as the user picks or clicks a path — the leave guard runs first, so a
 * cancelled open never reaches here — and goes away if that open fails: a
 * file found gone is dropped on click. Save as, delete and rename are
 * followed whoever acted, and so is a current file found gone (the anomaly).
 */
export default class extends Controller<HTMLElement> {
    static values = { maxEntries: { type: Number, default: 50 }, i18n: Object };
    static targets = ['list', 'empty', 'loading'];

    declare readonly maxEntriesValue: number;
    declare readonly i18nValue: FileEntryI18n;
    declare readonly listTarget: HTMLElement;
    declare readonly emptyTarget: HTMLElement;
    declare readonly loadingTarget: HTMLElement;

    #entries = new FileEntries(() => this.i18nValue);
    #unsubscribers: Array<() => void> = [];

    connect(): void {
        this.#entries.connect();
        this.#unsubscribers = [
            onModeShown((mode) => {
                this.element.hidden = mode !== 'single';
            }),
            on('editor:nav-change_file-failed', ({ action }) => this.#remove(action.path)),
            // A new path the user just created, not yet in the history built
            // from Open and history clicks (see EDITOR_FIX.md #5).
            on('editor:do-save_as-succeeded', ({ action }) => this.#push(action.path)),
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
        const path = await pickPath('file');
        if (path === null) {
            return;
        }

        this.#push(path);
        this.#entries.open(path);
    }

    openHistoryEntry(event: Event): void {
        event.preventDefault();
        const path = entryPath(event);
        this.#push(path);
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
