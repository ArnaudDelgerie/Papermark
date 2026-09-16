import { Controller } from '@hotwired/stimulus';
import { confirmDialog } from '../utils/confirm-dialog.js';
import { dispatchOpenFile } from '../utils/editor-open.js';
import { deleteFile, renameFile } from '../utils/file-actions.js';
import { renameDialog } from '../utils/rename-dialog.js';
import { pickPath } from '../utils/tauri.js';
import { showToast } from '../utils/toast.js';

const STORAGE_KEY = 'editor.single.history';

/**
 * History of files opened in single mode: sessionStorage only (lost on app
 * restart, accepted — see EDITOR_FOLDER_MODE.md). Entries are added as soon
 * as the user picks or clicks a path, whether the open that follows succeeds
 * or not; the leave guard (editor_controller, window-level) runs first, so a
 * cancelled open never reaches here.
 */
export default class extends Controller {
    static values = {
        maxEntries: { type: Number, default: 50 },
        deleteUrl: String,
        renameUrl: String,
        fileCsrfToken: String,
        i18n: Object,
    };

    static targets = ['list', 'empty'];

    connect() {
        this.#render();
    }

    async openFile() {
        const path = await pickPath('file');
        if (path === null) {
            return;
        }

        this.#pushHistory(path);
        dispatchOpenFile(path);
    }

    openHistoryEntry(event) {
        event.preventDefault();
        this.#pushHistory(event.currentTarget.dataset.path);
        dispatchOpenFile(event.currentTarget.dataset.path);
    }

    async deleteEntry(event) {
        event.preventDefault();
        const { path } = event.currentTarget.dataset;
        const name = path.split('/').pop();

        const confirmed = await confirmDialog({
            message: this.i18nValue.deleteConfirmMessage,
            question: (this.i18nValue.deleteConfirmQuestion ?? '').replace('{name}', name),
            continueLabel: this.i18nValue.delete,
        });
        if (!confirmed) {
            return;
        }

        try {
            await deleteFile(this.deleteUrlValue, this.fileCsrfTokenValue, path);
            showToast('success', this.i18nValue.deleted ?? 'File deleted');
            this.#writeHistory(this.#readHistory().filter((entry) => entry !== path));
            this.#render();
        } catch (err) {
            console.error('Failed to delete file:', err);
            showToast('error', err.message || this.i18nValue.deleteFailed || 'Failed to delete file');
        }
    }

    async renameEntry(event) {
        event.preventDefault();
        const { path } = event.currentTarget.dataset;
        const currentName = path.split('/').pop();

        const newName = await renameDialog({
            currentName,
            message: (this.i18nValue.renamePrompt ?? '').replace('{name}', currentName),
            continueLabel: this.i18nValue.rename,
        });
        if (newName === null) {
            return;
        }

        try {
            const newPath = await renameFile(this.renameUrlValue, this.fileCsrfTokenValue, path, newName);
            showToast('success', this.i18nValue.renamed ?? 'File renamed');
            // In place: renaming isn't a re-open, so it doesn't reorder the history.
            this.#writeHistory(this.#readHistory().map((entry) => (entry === path ? newPath : entry)));
            this.#render();
        } catch (err) {
            console.error('Failed to rename file:', err);
            showToast('error', err.message || this.i18nValue.renameFailed || 'Failed to rename file');
        }
    }

    #pushHistory(path) {
        const history = this.#readHistory().filter((entry) => entry !== path);
        history.unshift(path);
        this.#writeHistory(history.slice(0, this.maxEntriesValue));
        this.#render();
    }

    #readHistory() {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch {
            return [];
        }
    }

    #writeHistory(history) {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history));
        } catch {
            // sessionStorage unavailable (private mode, quota…): history just won't persist.
        }
    }

    #render() {
        const history = this.#readHistory();

        this.listTarget.innerHTML = '';
        for (const path of history) {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.href = '#';
            link.title = path;
            link.dataset.path = path;
            link.dataset.action = 'click->mode-single#openHistoryEntry';
            link.setAttribute('data-editor-leave-guard', '');

            const label = document.createElement('span');
            label.className = 'mode-tree-label';
            label.textContent = path.split('/').pop();
            link.append(label);

            const actions = document.createElement('span');
            actions.className = 'mode-entry-actions';

            const renameBtn = document.createElement('button');
            renameBtn.type = 'button';
            renameBtn.className = 'mode-entry-action mode-entry-action--rename';
            renameBtn.title = this.i18nValue.rename ?? 'Rename';
            renameBtn.dataset.path = path;
            renameBtn.dataset.action = 'click->mode-single#renameEntry';
            actions.append(renameBtn);

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'mode-entry-action mode-entry-action--delete';
            deleteBtn.title = this.i18nValue.delete ?? 'Delete';
            deleteBtn.dataset.path = path;
            deleteBtn.dataset.action = 'click->mode-single#deleteEntry';
            actions.append(deleteBtn);

            li.append(link, actions);
            this.listTarget.append(li);
        }

        this.listTarget.hidden = history.length === 0;
        this.emptyTarget.hidden = history.length > 0;
    }
}
