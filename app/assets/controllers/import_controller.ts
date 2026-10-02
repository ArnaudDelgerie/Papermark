import { Controller } from '@hotwired/stimulus';
import { emit, on } from '../editor/events';
import { renderReportHeader } from '../utils/report-header';
import { type IpcI18n, type PathFilter, pickPath } from '../utils/tauri';
import { showToast } from '../utils/toast';

export interface I18n {
    noSource: string;
    /** UX-13, lot 10: what each path field says while it is empty. */
    noArchiveSelected: string;
    noParentSelected: string;
    done: string;
    /** The archive picker's zip filter name, translated. */
    zipFilter: string;
    ipc: IpcI18n;
    report: { title: string; empty: string; count_one: string; count_other: string; clear: string };
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
    static targets = ['archiveBrowseButton', 'parentBrowseButton', 'archivePath', 'parentPath', 'importButton', 'report'];

    static values = { i18n: Object };

    declare readonly archiveBrowseButtonTarget: HTMLButtonElement;
    declare readonly parentBrowseButtonTarget: HTMLButtonElement;
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
        // UX-13, lot 10: an empty field says why nothing shows, instead of
        // three blank boxes on a first opening.
        this.#showArchivePath();
        this.#showParentPath();

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
        const path = await this.#pick(this.archiveBrowseButtonTarget, 'file', [
            { name: this.i18nValue.zipFilter, extensions: ['zip'] },
        ]);
        if (path === null) {
            return;
        }

        this.#archivePath = path;
        this.#showArchivePath();

        // Pre-filled with the archive's own folder, without overwriting a
        // destination the user already picked (see EDITOR_IMPORT.md,
        // "Destination").
        if (!this.#parentPath) {
            this.#parentPath = dirname(path);
            this.#showParentPath();
        }
    }

    async browseParent(): Promise<void> {
        const path = await this.#pick(this.parentBrowseButtonTarget, 'directory');
        if (path === null) {
            return;
        }

        this.#parentPath = path;
        this.#showParentPath();
    }

    // UX-13, lot 10: a set path carries its whole content in a title, for
    // what the ellipsis cut; an empty one says why nothing shows.

    #showArchivePath(): void {
        this.#showPath(this.archivePathTarget, this.#archivePath, this.i18nValue.noArchiveSelected);
    }

    #showParentPath(): void {
        this.#showPath(this.parentPathTarget, this.#parentPath, this.i18nValue.noParentSelected);
    }

    #showPath(target: HTMLElement, path: string, placeholder: string): void {
        target.textContent = path || placeholder;
        if (path) {
            target.title = path;
        } else {
            target.removeAttribute('title');
        }
    }

    /**
     * The Browse button stays down until the picker answers, whatever the
     * invoke does with it (FRT-07, lot 08). The parent folder picker passes
     * no filter: a directory picker has none.
     */
    async #pick(
        button: HTMLButtonElement,
        kind: 'file' | 'directory',
        filters: PathFilter[] = [],
    ): Promise<string | null> {
        button.disabled = true;
        try {
            return await pickPath(kind, this.i18nValue.ipc, filters);
        } finally {
            button.disabled = false;
        }
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
        renderReportHeader(this.reportTarget, i18n.title, i18n.clear, () => this.#clearReport());

        if (ignoredEntries.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = i18n.empty;
            this.reportTarget.appendChild(empty);
        } else {
            // Only the count at first (ARC-13): a project's worth of ignored
            // entries can run into the thousands, the list is behind a toggle.
            const details = document.createElement('details');
            details.className = 'export-report-details';
            const summary = document.createElement('summary');
            summary.textContent = (ignoredEntries.length === 1 ? i18n.count_one : i18n.count_other).replace('{count}', String(ignoredEntries.length));
            details.appendChild(summary);

            const list = document.createElement('ul');
            for (const entry of ignoredEntries) {
                const item = document.createElement('li');
                item.className = 'export-report-item';
                item.textContent = entry;
                list.appendChild(item);
            }
            details.appendChild(list);
            this.reportTarget.appendChild(details);
        }

        this.reportTarget.hidden = false;
    }

    #clearReport(): void {
        this.reportTarget.replaceChildren();
        this.reportTarget.hidden = true;
    }
}
