import { Controller } from '@hotwired/stimulus';
import { confirmDialog } from '../utils/confirm-dialog.js';
import { MODE_CHANGE_REQUEST_EVENT } from '../utils/editor-mode.js';
import { CURRENT_DIR_CHANGE_REQUEST_EVENT, CURRENT_DIR_UPDATED_EVENT } from '../utils/current-directory.js';
import { FILE_SAVED_AS_EVENT, dispatchFileDeleted, dispatchFileRenamed, dispatchOpenFile } from '../utils/editor-open.js';
import { deleteFile, renameFile } from '../utils/file-actions.js';
import { renameDialog } from '../utils/rename-dialog.js';
import { showLoading } from '../utils/sidebar-loading.js';
import { showToast } from '../utils/toast.js';

/**
 * The tree of the folder held in session. It doesn't own that folder —
 * current-directory does — so it only listens: loader while the change is in
 * flight, frame reload once it is settled (see EDITOR_REACTIVITY.md).
 */
export default class extends Controller {
    static values = { deleteUrl: String, renameUrl: String, fileCsrfToken: String, i18n: Object };

    static targets = ['treeFrame'];

    #onFileSavedAs = () => this.#refreshTree();

    // The loader goes up before the request leaves, so the wait is visible
    // from the very first moment rather than once the frame turns busy.
    #onCurrentDirChangeRequest = () => showLoading(this.treeFrameTarget);

    #onCurrentDirUpdated = () => this.#refreshTree();

    // The column owns whether it shows: the switch only announces the mode.
    #onModeChangeRequest = (event) => {
        this.element.hidden = event.detail.mode !== 'dir';
    };

    connect() {
        window.addEventListener(MODE_CHANGE_REQUEST_EVENT, this.#onModeChangeRequest);
        window.addEventListener(FILE_SAVED_AS_EVENT, this.#onFileSavedAs);
        window.addEventListener(CURRENT_DIR_CHANGE_REQUEST_EVENT, this.#onCurrentDirChangeRequest);
        window.addEventListener(CURRENT_DIR_UPDATED_EVENT, this.#onCurrentDirUpdated);
    }

    disconnect() {
        window.removeEventListener(MODE_CHANGE_REQUEST_EVENT, this.#onModeChangeRequest);
        window.removeEventListener(FILE_SAVED_AS_EVENT, this.#onFileSavedAs);
        window.removeEventListener(CURRENT_DIR_CHANGE_REQUEST_EVENT, this.#onCurrentDirChangeRequest);
        window.removeEventListener(CURRENT_DIR_UPDATED_EVENT, this.#onCurrentDirUpdated);
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
    #refreshTree() {
        this.treeFrameTarget.reload();
    }
}
