import { Controller } from '@hotwired/stimulus';
import { confirmDialog } from '../utils/confirm-dialog.js';
import { MODE_CHANGE_REQUEST_EVENT } from '../utils/editor-mode.js';
import {
    FILE_DELETED_EVENT,
    FILE_RENAMED_EVENT,
    FILE_SAVED_AS_EVENT,
    dispatchFileChangeRequest,
    dispatchFileDeleted,
    dispatchFileRenamed,
    dispatchFileUpdated,
} from '../utils/editor-open.js';
import { deleteFile, renameFile, setCurrentFile } from '../utils/file-actions.js';
import { renameDialog } from '../utils/rename-dialog.js';
import { pickPath } from '../utils/tauri.js';
import { showToast } from '../utils/toast.js';

const STORAGE_KEY = 'editor.single.history';

/**
 * History of files opened in single mode: sessionStorage only (lost on app
 * restart, accepted — see EDITOR_FOLDER_MODE.md). Entries are added as soon
 * as the user picks or clicks a path; the leave guard (editor_controller,
 * window-level) runs first, so a cancelled open never reaches here. Deleted
 * and renamed files are followed through the file events, whoever acted on
 * them — including a file found gone (see EDITOR_REACTIVITY.md).
 */
export default class extends Controller {
    static values = {
        maxEntries: { type: Number, default: 50 },
        setFileUrl: String,
        deleteUrl: String,
        renameUrl: String,
        fileCsrfToken: String,
        i18n: Object,
    };

    static targets = ['list', 'empty', 'loading'];

    #onFileSavedAs = (event) => this.#pushHistory(event.detail.path);

    #onFileDeleted = (event) => {
        this.#writeHistory(this.#readHistory().filter((entry) => entry !== event.detail.path));
        this.#render();
    };

    // In place: renaming isn't a re-open, so it doesn't reorder the history.
    #onFileRenamed = (event) => {
        const { oldPath, newPath } = event.detail;
        this.#writeHistory(this.#readHistory().map((entry) => (entry === oldPath ? newPath : entry)));
        this.#render();
    };

    // The column owns whether it shows: the switch only announces the mode.
    #onModeChangeRequest = (event) => {
        this.element.hidden = event.detail.mode !== 'single';
    };

    connect() {
        window.addEventListener(MODE_CHANGE_REQUEST_EVENT, this.#onModeChangeRequest);
        this.#render();
        // Save as: a new path the user just created, not yet in the history
        // built from Open/history clicks (see EDITOR_FIX.md #5).
        window.addEventListener(FILE_SAVED_AS_EVENT, this.#onFileSavedAs);
        window.addEventListener(FILE_DELETED_EVENT, this.#onFileDeleted);
        window.addEventListener(FILE_RENAMED_EVENT, this.#onFileRenamed);
    }

    disconnect() {
        window.removeEventListener(MODE_CHANGE_REQUEST_EVENT, this.#onModeChangeRequest);
        window.removeEventListener(FILE_SAVED_AS_EVENT, this.#onFileSavedAs);
        window.removeEventListener(FILE_DELETED_EVENT, this.#onFileDeleted);
        window.removeEventListener(FILE_RENAMED_EVENT, this.#onFileRenamed);
    }

    async openFile() {
        const path = await pickPath('file');
        if (path === null) {
            return;
        }

        this.#pushHistory(path);
        await this.#open(path);
    }

    async openHistoryEntry(event) {
        event.preventDefault();
        const { path } = event.currentTarget.dataset;
        this.#pushHistory(path);
        await this.#open(path);
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
            const { state } = await deleteFile(this.deleteUrlValue, this.fileCsrfTokenValue, path);
            showToast('success', this.i18nValue.deleted ?? 'File deleted');
            dispatchFileDeleted(path, state);
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
            const { path: newPath, state } = await renameFile(this.renameUrlValue, this.fileCsrfTokenValue, path, newName);
            showToast('success', this.i18nValue.renamed ?? 'File renamed');
            dispatchFileRenamed(path, newPath, state);
        } catch (err) {
            console.error('Failed to rename file:', err);
            showToast('error', err.message || this.i18nValue.renameFailed || 'Failed to rename file');
        }
    }

    // The sidebar makes the file the current one and announces it; the editor
    // fetches the content on the update. A 404 means the file is gone.
    async #open(path) {
        dispatchFileChangeRequest(path);
        try {
            const { state } = await setCurrentFile(this.setFileUrlValue, this.fileCsrfTokenValue, path);
            dispatchFileUpdated(state);
        } catch (err) {
            console.error('Failed to open file:', err);
            showToast('error', err.message || 'Failed to open file');
            if (err.status === 404) {
                dispatchFileDeleted(path);
            }
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

        this.loadingTarget.hidden = true;
        this.listTarget.hidden = history.length === 0;
        this.emptyTarget.hidden = history.length > 0;
    }
}
