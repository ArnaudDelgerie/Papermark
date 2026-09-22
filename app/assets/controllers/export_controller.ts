import { Controller } from '@hotwired/stimulus';
import { type EditorState, on } from '../editor/events';
import { type SaveFilter, pickPath, savePath } from '../utils/tauri';
import { showToast } from '../utils/toast';

const ZIP_FILTERS: SaveFilter[] = [{ name: 'Zip', extensions: ['zip'] }];

type Kind = 'file' | 'directory';

/** A reference ExportController left at its original path. */
interface ReportIssue {
    originalTarget: string;
    reason: string;
    referencingPath: string;
}

interface I18n {
    noSource: string;
    failed: string;
    done: string;
    report: { title: string; empty: string; count_one: string; count_other: string; reason: Record<string, string> };
}

function dirname(path: string): string {
    const idx = path.lastIndexOf('/');

    return idx > 0 ? path.slice(0, idx) : '/';
}

function basename(path: string): string {
    return path.slice(path.lastIndexOf('/') + 1);
}

/**
 * The export block of the Archive modal (see EDITOR_EXPORT.md): file/folder pick, the
 * "include external .md" option, save_path with a zip filter, then a POST
 * that writes the archive server-side and returns a report of the
 * references left at their original path.
 */
export default class extends Controller {
    static targets = ['kindFileRadio', 'kindDirectoryRadio', 'sourcePath', 'includeExternalMarkdown', 'exportButton', 'report'];

    static values = {
        // At load: 'file' in single mode, 'directory' in dir mode (see
        // EDITOR_REACTIVITY.md). The state then takes over (see #follow()).
        initialKind: { type: String, default: 'file' },
        initialPath: String,
        initialDirectory: String,
        csrfToken: String,
        runUrl: String,
        i18n: Object,
    };

    declare readonly kindFileRadioTarget: HTMLInputElement;
    declare readonly kindDirectoryRadioTarget: HTMLInputElement;
    declare readonly sourcePathTarget: HTMLElement;
    declare readonly includeExternalMarkdownTarget: HTMLInputElement;
    declare readonly exportButtonTarget: HTMLButtonElement;
    declare readonly reportTarget: HTMLElement;
    declare readonly initialKindValue: string;
    declare readonly initialPathValue: string;
    declare readonly initialDirectoryValue: string;
    declare readonly csrfTokenValue: string;
    declare readonly runUrlValue: string;
    declare readonly i18nValue: I18n;

    #kind: Kind = 'file';
    #filePath = '';
    #directoryPath = '';
    #unsubscribers: Array<() => void> = [];

    connect(): void {
        this.#filePath = this.initialPathValue;
        this.#directoryPath = this.initialDirectoryValue;
        this.#show('directory' === this.initialKindValue ? 'directory' : 'file');

        // The frame is loaded once and never emptied: the source follows every
        // event that can change the mode, the file or the folder, even over a
        // source the user picked by hand in the modal.
        const follow = ({ state }: { state: EditorState }): void => this.#follow(state);
        this.#unsubscribers = [
            on('editor:nav-switch_mode-succeeded', follow),
            on('editor:nav-change_dir-succeeded', follow),
            on('editor:nav-change_file-succeeded', follow),
            on('editor:nav-new_file-succeeded', follow),
            on('editor:do-save_as-succeeded', follow),
            on('editor:do-delete-succeeded', follow),
            on('editor:do-rename-succeeded', follow),
            on('editor:do-import-succeeded', follow),
            on('editor:state-resynced', follow),
        ];
    }

    disconnect(): void {
        this.#unsubscribers.forEach((unsubscribe) => unsubscribe());
        this.#unsubscribers = [];
    }

    /** The current file in single mode, the current folder in dir mode; the other keeps its path. */
    #follow(state: EditorState): void {
        this.#filePath = state.file ?? '';
        this.#directoryPath = state.dir ?? '';
        this.#show(state.mode === 'dir' ? 'directory' : 'file');
    }

    #show(kind: Kind): void {
        this.#kind = kind;
        (kind === 'file' ? this.kindFileRadioTarget : this.kindDirectoryRadioTarget).checked = true;
        this.#updateSourceDisplay();
    }

    changeKind(event: Event): void {
        this.#kind = (event.target as HTMLInputElement).value as Kind;
        this.#updateSourceDisplay();
    }

    async browse(): Promise<void> {
        const path = await pickPath(this.#kind);
        if (path === null) {
            return;
        }

        if (this.#kind === 'file') {
            this.#filePath = path;
        } else {
            this.#directoryPath = path;
        }

        this.#updateSourceDisplay();
    }

    async run(): Promise<void> {
        const sourcePath = this.#kind === 'file' ? this.#filePath : this.#directoryPath;
        if (!sourcePath) {
            showToast('error', this.i18nValue.noSource);
            return;
        }

        const name = basename(sourcePath);
        const defaultName = this.#kind === 'file' ? name.replace(/\.[^./]+$/, '') : name;

        const target = await savePath(`${defaultName}.zip`, dirname(sourcePath), ZIP_FILTERS);
        if (target === null) {
            return;
        }

        this.exportButtonTarget.disabled = true;
        this.reportTarget.hidden = true;

        const formData = new FormData();
        formData.append('source', sourcePath);
        formData.append('target', target);
        if (this.includeExternalMarkdownTarget.checked) {
            formData.append('includeExternalMarkdown', '1');
        }

        try {
            const response = await fetch(this.runUrlValue, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrfTokenValue },
                body: formData,
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data.genericErrors?.[0] || this.i18nValue.failed);
            }

            showToast('success', this.i18nValue.done.replace('{path}', data.path));
            this.#renderReport(data.issues || []);
        } catch (err) {
            console.error('Failed to export archive:', err);
            showToast('error', (err as Error).message || this.i18nValue.failed);
        } finally {
            this.exportButtonTarget.disabled = false;
        }
    }

    #updateSourceDisplay(): void {
        this.sourcePathTarget.textContent = this.#kind === 'file' ? this.#filePath : this.#directoryPath;
    }

    #renderReport(issues: ReportIssue[]): void {
        const i18n = this.i18nValue.report;
        this.reportTarget.replaceChildren();

        const title = document.createElement('h2');
        title.textContent = i18n.title;
        this.reportTarget.appendChild(title);

        if (issues.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = i18n.empty;
            this.reportTarget.appendChild(empty);
        } else {
            // Only the count at first: the list can be long, it is behind a toggle.
            const details = document.createElement('details');
            details.className = 'export-report-details';
            const summary = document.createElement('summary');
            summary.textContent = (issues.length === 1 ? i18n.count_one : i18n.count_other).replace('{count}', String(issues.length));
            details.appendChild(summary);

            const list = document.createElement('ul');
            for (const issue of issues) {
                const item = document.createElement('li');
                item.className = 'export-report-item';

                const target = document.createElement('span');
                target.className = 'export-report-target';
                target.textContent = issue.originalTarget;

                const reason = document.createElement('span');
                reason.className = 'export-report-reason';
                reason.textContent = i18n.reason[issue.reason] || issue.reason;

                const referencing = document.createElement('span');
                referencing.className = 'export-report-referencing';
                referencing.textContent = issue.referencingPath;

                item.append(target, reason, referencing);
                list.appendChild(item);
            }
            details.appendChild(list);
            this.reportTarget.appendChild(details);
        }

        this.reportTarget.hidden = false;
    }
}
