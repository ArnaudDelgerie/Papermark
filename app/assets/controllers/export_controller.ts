import { Controller } from '@hotwired/stimulus';
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
    report: { title: string; empty: string; reason: Record<string, string> };
}

function dirname(path: string): string {
    const idx = path.lastIndexOf('/');

    return idx > 0 ? path.slice(0, idx) : '/';
}

function basename(path: string): string {
    return path.slice(path.lastIndexOf('/') + 1);
}

/**
 * The export archive page (see EDITOR_EXPORT.md): file/folder pick, the
 * "include external .md" option, save_path with a zip filter, then a POST
 * that writes the archive server-side and returns a report of the
 * references left at their original path.
 */
export default class extends Controller {
    static targets = ['kindFileRadio', 'kindDirectoryRadio', 'sourcePath', 'includeExternalMarkdown', 'exportButton', 'report'];

    static values = {
        // 'file' in single mode, 'directory' in dir mode (see EDITOR_REACTIVITY.md).
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

    connect(): void {
        this.#filePath = this.initialPathValue;
        this.#directoryPath = this.initialDirectoryValue;
        this.#kind = this.initialKindValue === 'directory' ? 'directory' : 'file';

        (this.#kind === 'file' ? this.kindFileRadioTarget : this.kindDirectoryRadioTarget).checked = true;
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
                throw new Error(data.error || this.i18nValue.failed);
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
            this.reportTarget.appendChild(list);
        }

        this.reportTarget.hidden = false;
    }
}
