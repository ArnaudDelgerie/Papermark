import { Controller } from '@hotwired/stimulus';
import { confirmDialog } from '../utils/confirm-dialog.js';
import { FILE_SAVED_AS_EVENT, dispatchFileDeleted, dispatchFileRenamed, dispatchOpenFile } from '../utils/editor-open.js';
import { deleteFile, renameFile } from '../utils/file-actions.js';
import { renameDialog } from '../utils/rename-dialog.js';
import { pickPath } from '../utils/tauri.js';
import { showToast } from '../utils/toast.js';

export default class extends Controller {
    static values = { treeUrl: String, deleteUrl: String, renameUrl: String, fileCsrfToken: String, i18n: Object };
    static targets = ['form', 'pathInput', 'openButton', 'tree'];

    #onFileSavedAs = () => this.#refreshTree();

    connect() {
        window.addEventListener(FILE_SAVED_AS_EVENT, this.#onFileSavedAs);
    }

    disconnect() {
        window.removeEventListener(FILE_SAVED_AS_EVENT, this.#onFileSavedAs);
    }

    // A real form submit, not fetch: the server redirects back to this page
    // once the directory is stored in session (see EDITOR_FOLDER_MODE.md).
    // The spinner isn't cleared on success: the page navigates away anyway.
    async openDirectory() {
        const path = await pickPath('directory');
        if (path === null) {
            return;
        }

        this.pathInputTarget.value = path;
        this.openButtonTarget.disabled = true;
        this.openButtonTarget.classList.add('is-loading');

        // Submitting right away can start navigation before the browser has
        // painted the spinner class — on a fast (small-directory) response
        // it never becomes visible. Two rAFs guarantee a paint happened first.
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                this.formTarget.requestSubmit();
            });
        });
    }

    openFile(event) {
        event.preventDefault();
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
            this.#refreshTree();
            dispatchFileDeleted(path);
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
            this.#refreshTree();
            dispatchFileRenamed(path, newPath);
        } catch (err) {
            console.error('Failed to rename file:', err);
            showToast('error', err.message || this.i18nValue.renameFailed || 'Failed to rename file');
        }
    }

    // No path check against the open directory: a re-render showing the same
    // tree is harmless, and re-deriving "is this under the open dir" here
    // would just duplicate what the server already resolves from session.
    async #refreshTree() {
        try {
            const response = await fetch(this.treeUrlValue);
            if (!response.ok) {
                return;
            }
            this.treeTarget.innerHTML = await response.text();
        } catch (err) {
            console.error('Failed to refresh the folder tree:', err);
        }
    }
}
