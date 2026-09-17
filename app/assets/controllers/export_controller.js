import { Controller } from '@hotwired/stimulus';
import { pickPath, savePath } from '../utils/tauri.js';
import { showToast } from '../utils/toast.js';

const ZIP_FILTERS = [{ name: 'Zip', extensions: ['zip'] }];

function dirname(path) {
    const idx = path.lastIndexOf('/');

    return idx > 0 ? path.slice(0, idx) : '/';
}

function basename(path) {
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
        initialPath: String,
        initialDirectory: String,
        csrfToken: String,
        runUrl: String,
        i18n: Object,
    };

    #kind = 'file';
    #filePath = '';
    #directoryPath = '';

    connect() {
        this.#filePath = this.initialPathValue;
        this.#directoryPath = this.initialDirectoryValue;
        this.#kind = this.#filePath !== '' ? 'file' : 'directory';

        (this.#kind === 'file' ? this.kindFileRadioTarget : this.kindDirectoryRadioTarget).checked = true;
        this.#updateSourceDisplay();
    }

    changeKind(event) {
        this.#kind = event.target.value;
        this.#updateSourceDisplay();
    }

    async browse() {
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

    async run() {
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
            showToast('error', err.message || this.i18nValue.failed);
        } finally {
            this.exportButtonTarget.disabled = false;
        }
    }

    #updateSourceDisplay() {
        this.sourcePathTarget.textContent = this.#kind === 'file' ? this.#filePath : this.#directoryPath;
    }

    #renderReport(issues) {
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
