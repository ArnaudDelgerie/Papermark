import { Controller } from '@hotwired/stimulus';
import { emit, on } from '../editor/events';
import { pickPath } from '../utils/tauri';
import { showToast } from '../utils/toast';

interface I18n {
    noSource: string;
    done: string;
    report: { title: string; empty: string };
}

function dirname(path: string): string {
    const idx = path.lastIndexOf('/');

    return idx > 0 ? path.slice(0, idx) : '/';
}

/**
 * The import block of the Archive modal (see EDITOR_IMPORT.md): pick a zip
 * archive and a destination parent folder, then ask the master to extract it
 * (`do-import`). The answer carries the state the archive left the editor in,
 * which the columns and the editor follow on their own; this block only shows
 * the toast and the report of the entries it left out, and gets its button
 * back. The modal stays open. The leave guard, on the button, has already
 * asked about unsaved work when the click gets here.
 */
export default class extends Controller {
    static targets = ['archivePath', 'parentPath', 'importButton', 'report'];

    static values = { i18n: Object };

    declare readonly archivePathTarget: HTMLElement;
    declare readonly parentPathTarget: HTMLElement;
    declare readonly importButtonTarget: HTMLButtonElement;
    declare readonly reportTarget: HTMLElement;
    declare readonly i18nValue: I18n;

    #unsubscribers: Array<() => void> = [];
    /** Between this block's request and its answer: the events are heard by all. */
    #running = false;

    #archivePath = '';
    #parentPath = '';

    connect(): void {
        this.#unsubscribers = [
            on('editor:do-import-succeeded', ({ action }) => {
                if (!this.#running) {
                    return;
                }
                this.#settle();
                showToast('success', this.i18nValue.done.replace('{path}', action.destination));
                this.#renderReport(action.ignoredEntries);
            }),
            // The master already toasted the failure.
            on('editor:do-import-failed', () => {
                if (this.#running) {
                    this.#settle();
                }
            }),
        ];
    }

    disconnect(): void {
        this.#unsubscribers.forEach((unsubscribe) => unsubscribe());
        this.#unsubscribers = [];
    }

    async browseArchive(): Promise<void> {
        const path = await pickPath('file');
        if (path === null) {
            return;
        }

        this.#archivePath = path;
        this.archivePathTarget.textContent = path;

        // Pre-filled with the archive's own folder, without overwriting a
        // destination the user already picked (see EDITOR_IMPORT.md,
        // "Destination").
        if (!this.#parentPath) {
            this.#parentPath = dirname(path);
            this.parentPathTarget.textContent = this.#parentPath;
        }
    }

    async browseParent(): Promise<void> {
        const path = await pickPath('directory');
        if (path === null) {
            return;
        }

        this.#parentPath = path;
        this.parentPathTarget.textContent = path;
    }

    run(): void {
        if (!this.#archivePath || !this.#parentPath) {
            showToast('error', this.i18nValue.noSource);
            return;
        }

        this.#running = true;
        this.importButtonTarget.disabled = true;
        this.reportTarget.hidden = true;

        emit('editor:do-import-requested', { action: { archive: this.#archivePath, parentDir: this.#parentPath } });
    }

    #settle(): void {
        this.#running = false;
        this.importButtonTarget.disabled = false;
    }

    #renderReport(ignoredEntries: string[]): void {
        const i18n = this.i18nValue.report;
        this.reportTarget.replaceChildren();

        const title = document.createElement('h2');
        title.textContent = i18n.title;
        this.reportTarget.appendChild(title);

        if (ignoredEntries.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = i18n.empty;
            this.reportTarget.appendChild(empty);
        } else {
            const list = document.createElement('ul');
            for (const entry of ignoredEntries) {
                const item = document.createElement('li');
                item.className = 'export-report-item';
                item.textContent = entry;
                list.appendChild(item);
            }
            this.reportTarget.appendChild(list);
        }

        this.reportTarget.hidden = false;
    }
}
