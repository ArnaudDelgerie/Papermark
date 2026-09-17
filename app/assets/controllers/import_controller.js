import { Controller } from '@hotwired/stimulus';
import { pickPath } from '../utils/tauri.js';
import { showToast } from '../utils/toast.js';

function dirname(path) {
    const idx = path.lastIndexOf('/');

    return idx > 0 ? path.slice(0, idx) : '/';
}

/**
 * The import block of the Archive page (see EDITOR_IMPORT.md): pick a zip
 * archive and a destination parent folder, then a POST that extracts it
 * server-side and returns a report of the entries it left out. The server
 * already pointed the session at the result (see
 * ExportController::importRun()); opening it is a plain navigation to
 * app_home, which redirects to the right editor mode.
 */
export default class extends Controller {
    static targets = ['archivePath', 'parentPath', 'importButton', 'report'];

    static values = {
        csrfToken: String,
        runUrl: String,
        homeUrl: String,
        i18n: Object,
    };

    #archivePath = '';
    #parentPath = '';

    async browseArchive() {
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

    async browseParent() {
        const path = await pickPath('directory');
        if (path === null) {
            return;
        }

        this.#parentPath = path;
        this.parentPathTarget.textContent = path;
    }

    async run() {
        if (!this.#archivePath || !this.#parentPath) {
            showToast('error', this.i18nValue.noSource);
            return;
        }

        this.importButtonTarget.disabled = true;
        this.reportTarget.hidden = true;

        const formData = new FormData();
        formData.append('archive', this.#archivePath);
        formData.append('parentDir', this.#parentPath);

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

            showToast('success', this.i18nValue.done.replace('{path}', data.destination));
            this.#renderReport(data.ignoredEntries || []);

            if (data.openMode) {
                window.location.href = this.homeUrlValue;
            }
        } catch (err) {
            console.error('Failed to import archive:', err);
            showToast('error', err.message || this.i18nValue.failed);
        } finally {
            this.importButtonTarget.disabled = false;
        }
    }

    #renderReport(ignoredEntries) {
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
