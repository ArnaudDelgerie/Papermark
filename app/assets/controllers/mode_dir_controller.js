import { Controller } from '@hotwired/stimulus';
import { confirmDialog } from '../utils/confirm-dialog.js';
import { MODE_CHANGE_REQUEST_EVENT } from '../utils/editor-mode.js';
import { CURRENT_DIR_CHANGE_REQUEST_EVENT, CURRENT_DIR_UPDATED_EVENT } from '../utils/current-directory.js';
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
import { showLoading } from '../utils/sidebar-loading.js';
import { showToast } from '../utils/toast.js';

/**
 * The tree of the current folder. It doesn't change that folder —
 * current-directory does — so it only listens: loader while the change is in
 * flight, frame reload once it is settled. It also reloads whenever a file may
 * have appeared, gone or changed name, whoever acted on it — not on a mode
 * switch, the tree only depends on the folder (see EDITOR_REACTIVITY.md).
 */
export default class extends Controller {
    static values = { setFileUrl: String, deleteUrl: String, renameUrl: String, fileCsrfToken: String, i18n: Object };

    static targets = ['treeFrame'];

    #onFileChanged = () => this.#refreshTree();

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
        window.addEventListener(FILE_SAVED_AS_EVENT, this.#onFileChanged);
        window.addEventListener(FILE_DELETED_EVENT, this.#onFileChanged);
        window.addEventListener(FILE_RENAMED_EVENT, this.#onFileChanged);
        window.addEventListener(CURRENT_DIR_CHANGE_REQUEST_EVENT, this.#onCurrentDirChangeRequest);
        window.addEventListener(CURRENT_DIR_UPDATED_EVENT, this.#onCurrentDirUpdated);
    }

    disconnect() {
        window.removeEventListener(MODE_CHANGE_REQUEST_EVENT, this.#onModeChangeRequest);
        window.removeEventListener(FILE_SAVED_AS_EVENT, this.#onFileChanged);
        window.removeEventListener(FILE_DELETED_EVENT, this.#onFileChanged);
        window.removeEventListener(FILE_RENAMED_EVENT, this.#onFileChanged);
        window.removeEventListener(CURRENT_DIR_CHANGE_REQUEST_EVENT, this.#onCurrentDirChangeRequest);
        window.removeEventListener(CURRENT_DIR_UPDATED_EVENT, this.#onCurrentDirUpdated);
    }

    // Makes the file the current one and announces it; the editor fetches
    // the content on the update. A 404 means the file is gone.
    async openFile(event) {
        event.preventDefault();
        const { path } = event.currentTarget.dataset;

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

    // No path check against the current folder: a re-render showing the same
    // tree is harmless, and re-deriving "is this under the folder" here would
    // just duplicate what the server already resolves from session. A reload
    // cancels the frame's own request still in flight (Turbo 8).
    #refreshTree() {
        this.treeFrameTarget.reload();
    }
}
